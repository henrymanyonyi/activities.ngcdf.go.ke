<?php

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Enums\InstructionKind;
use App\Enums\InstructionStatus;
use App\Exceptions\ActivityWorkflowException;
use App\Models\ActionInstruction;
use App\Models\Activity;
use App\Models\ActivityCost;
use App\Models\Department;
use App\Models\User;
use App\Notifications\ActivityAlert;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * After approving a memo, the CEO instructs Finance to process the imprest and
 * Procurement to buy the travel tickets (one instruction of each kind per
 * activity). The Head of that department sees an instruction sheet, never the
 * memo: the reference, dates, places, team, the CEO's note and only the cost
 * lines they act on. They acknowledge it, then record it done with a reference,
 * which alerts the Office of the CEO and the memo's own department. In-app
 * only; nothing leaves the system (BR-11).
 */
class ActionInstructions
{
    /** An instruction can be sent while the activity is still ahead or under way. */
    private const SENDABLE = [ActivityStatus::Approved, ActivityStatus::Postponed, ActivityStatus::InProgress];

    public function __construct(private AppSettings $settings, private Alerts $alerts) {}

    /** The department that acts on this kind: the one chosen in Settings, or the default by name. */
    public function department(InstructionKind $kind): ?Department
    {
        $id = $this->settings->int($kind->settingKey());

        return $id > 0
            ? Department::query()->find($id)
            : Department::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($kind->defaultDepartment())])->first();
    }

    /** Departments that receive instructions, so their Head sees the Instructions menu. */
    public function receives(User $user): bool
    {
        if (! $user->can('instructions.action') || ! isset($user->department_id)) {
            return false;
        }

        return collect(InstructionKind::cases())->contains(fn (InstructionKind $kind) => $this->department($kind)?->id === (int) $user->department_id)
            || ActionInstruction::query()->for($user)->exists();
    }

    /**
     * @param  list<InstructionKind>  $kinds
     * @return list<string> warnings, such as a department without an active Head
     */
    public function send(Activity $activity, array $kinds, ?string $note, User $user): array
    {
        if (! $user->can('instructions.send')) {
            throw new AuthorizationException('Only the CEO instructs Finance and Procurement.');
        }

        $kinds = array_values(array_unique($kinds, SORT_REGULAR));
        if ($kinds === []) {
            return [];
        }

        return DB::transaction(function () use ($activity, $kinds, $note, $user) {
            // Locked and re-read, so a double click cannot send the same instruction twice.
            $activity = Activity::query()->whereKey($activity->id)->lockForUpdate()->firstOrFail();
            if (! in_array($activity->status, self::SENDABLE, true)) {
                throw new ActivityWorkflowException("Finance and Procurement can be instructed only on an approved activity that has not ended; this one is {$activity->status->label()}.");
            }

            $warnings = [];
            foreach ($kinds as $kind) {
                if (ActionInstruction::query()->where('activity_id', $activity->id)->where('kind', $kind->value)->exists()) {
                    throw new ActivityWorkflowException("{$kind->office()} has already been instructed to {$this->lower($kind->label())} for this activity.");
                }

                $department = $this->department($kind)
                    ?? throw new ActivityWorkflowException("No {$kind->office()} department is set. Choose it in Reference data › Thresholds.");

                $instruction = ActionInstruction::create([
                    'activity_id' => $activity->id,
                    'kind' => $kind,
                    'department_id' => $department->id,
                    'note' => filled($note) ? trim($note) : null,
                    'status' => InstructionStatus::Sent,
                    'sent_by' => $user->id,
                    'sent_at' => now(),
                ]);

                $this->alerts->toHeadOf($department->id, ActivityAlert::instructionSent($instruction->setRelation('activity', $activity)));

                if (! User::query()->role(User::ROLE_HOD)->where('is_active', true)->where('department_id', $department->id)->exists()) {
                    $warnings[] = "{$department->name} has no active Head of Department yet; the instruction to {$this->lower($kind->label())} waits until one is set up.";
                }
            }

            return $warnings;
        });
    }

    public function acknowledge(ActionInstruction $instruction, User $user): void
    {
        $this->authorizeRecipient($instruction, $user);

        DB::transaction(function () use ($instruction, $user) {
            $locked = ActionInstruction::query()->whereKey($instruction->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== InstructionStatus::Sent) {
                throw new ActivityWorkflowException("This instruction is already {$this->lower($locked->status->label())}.");
            }

            $locked->update(['status' => InstructionStatus::Acknowledged, 'acknowledged_by' => $user->id, 'acknowledged_at' => now()]);
            $instruction->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function complete(ActionInstruction $instruction, string $reference, ?string $note, User $user): void
    {
        $this->authorizeRecipient($instruction, $user);

        if (trim($reference) === '') {
            throw new ActivityWorkflowException("Enter the {$this->lower($instruction->kind->referenceLabel())}.");
        }

        DB::transaction(function () use ($instruction, $reference, $note, $user) {
            $locked = ActionInstruction::query()->whereKey($instruction->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === InstructionStatus::Completed) {
                throw new ActivityWorkflowException('This instruction is already completed.');
            }

            $locked->update([
                'status' => InstructionStatus::Completed,
                'acknowledged_by' => $locked->acknowledged_by ?? $user->id,
                'acknowledged_at' => $locked->acknowledged_at ?? now(),
                'completed_by' => $user->id,
                'completed_at' => now(),
                'completion_reference' => mb_substr(trim($reference), 0, 100),
                'completion_note' => filled($note) ? trim($note) : null,
            ]);
            $instruction->setRawAttributes($locked->getAttributes(), true);

            $alert = ActivityAlert::instructionCompleted($locked->load('activity'));
            $this->alerts->toCeo($alert);
            $this->alerts->toStaffOfCeo($alert);
            $this->alerts->toDepartment($locked->activity, $alert);
        });
    }

    /**
     * The cost lines the department acts on: every line for Finance, the travel and vehicle hire lines for Procurement.
     *
     * @return Collection<int, ActivityCost>
     */
    public function costLines(ActionInstruction $instruction): Collection
    {
        $codes = $instruction->kind->costCodes();

        return $instruction->activity->costs()
            ->with(['category', 'participant.staff'])
            ->when($codes !== null, fn ($q) => $q->whereHas('category', fn ($c) => $c->whereIn('code', $codes)))
            ->orderBy('id')
            ->get();
    }

    private function authorizeRecipient(ActionInstruction $instruction, User $user): void
    {
        if (! $instruction->isFor($user)) {
            throw new AuthorizationException('Only the Head of the department instructed can act on this instruction.');
        }
    }

    private function lower(string $text): string
    {
        return mb_strtolower(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
