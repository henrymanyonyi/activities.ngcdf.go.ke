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
 * After approving a memo, the CEO instructs the departments that must act on
 * it: Finance to process the imprest, Procurement to buy the travel tickets
 * (one of each per activity), and any other department to do what the CEO
 * writes (one per department; 26 October 2026). The Head of that department
 * sees an instruction sheet, never the memo: the reference, dates, places,
 * team, the CEO's instruction and only the cost lines they act on (all for
 * Finance, travel for Procurement, none for any other department). They acknowledge it, then record it done with a reference,
 * which alerts the Office of the CEO and the memo's own department. In-app
 * only; nothing leaves the system (BR-11).
 */
class ActionInstructions
{
    /** An instruction can be sent while the activity is still ahead or under way. */
    private const SENDABLE = [ActivityStatus::Approved, ActivityStatus::Postponed, ActivityStatus::InProgress];

    public function __construct(private AppSettings $settings, private Alerts $alerts) {}

    /** The department that acts on this kind: the one chosen in Settings, or the default by name; null for "other" (the CEO chooses). */
    public function department(InstructionKind $kind): ?Department
    {
        if ($kind->settingKey() === null) {
            return null;
        }

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
     * @param  list<InstructionKind>  $kinds  Finance and/or Procurement (the "other" kind goes in $others)
     * @param  list<array{department_id: int|string, instruction: string}>  $others  any other department, with what it must do
     * @return list<string> warnings, such as a department without an active Head
     */
    public function send(Activity $activity, array $kinds, ?string $note, User $user, array $others = []): array
    {
        if (! $user->can('instructions.send')) {
            throw new AuthorizationException('Only the CEO instructs departments to act on an approved memo.');
        }

        $kinds = array_values(array_filter(array_unique($kinds, SORT_REGULAR), fn (InstructionKind $k) => $k !== InstructionKind::Other));
        $others = $this->otherInstructions($others);
        if ($kinds === [] && $others === []) {
            return [];
        }

        return DB::transaction(function () use ($activity, $kinds, $note, $user, $others) {
            // Locked and re-read, so a double click cannot send the same instruction twice.
            $activity = Activity::query()->whereKey($activity->id)->lockForUpdate()->firstOrFail();
            if (! in_array($activity->status, self::SENDABLE, true)) {
                throw new ActivityWorkflowException("Finance and Procurement can be instructed only on an approved activity that has not ended; this one is {$activity->status->label()}.");
            }

            $warnings = [];
            $sends = [];
            foreach ($kinds as $kind) {
                if (ActionInstruction::query()->where('activity_id', $activity->id)->where('kind', $kind->value)->exists()) {
                    throw new ActivityWorkflowException("{$kind->office()} has already been instructed to {$this->lower($kind->label())} for this activity.");
                }

                $department = $this->department($kind)
                    ?? throw new ActivityWorkflowException("No {$kind->office()} department is set. Choose it in Reference data › Thresholds.");
                $sends[] = [$kind, $department, filled($note) ? trim($note) : null, 'the instruction to '.$this->lower($kind->label())];
            }
            foreach ($others as [$department, $text]) {
                if (ActionInstruction::query()->where('activity_id', $activity->id)->where('kind', InstructionKind::Other->value)->where('department_id', $department->id)->exists()) {
                    throw new ActivityWorkflowException("{$department->name} has already been instructed on this activity.");
                }
                $sends[] = [InstructionKind::Other, $department, $text, 'the instruction'];
            }

            foreach ($sends as [$kind, $department, $text, $what]) {
                $instruction = ActionInstruction::create([
                    'activity_id' => $activity->id,
                    'kind' => $kind,
                    'department_id' => $department->id,
                    'note' => $text,
                    'status' => InstructionStatus::Sent,
                    'sent_by' => $user->id,
                    'sent_at' => now(),
                ]);

                $this->alerts->toHeadOf($department->id, ActivityAlert::instructionSent($instruction->setRelation('activity', $activity)->setRelation('department', $department)));

                if (! User::query()->role(User::ROLE_HOD)->where('is_active', true)->where('department_id', $department->id)->exists()) {
                    $warnings[] = "{$department->name} has no active Head of Department yet; {$what} waits until one is set up.";
                }
            }

            return $warnings;
        });
    }

    /**
     * Each other department once, active, with what it must do in writing.
     *
     * @param  list<array{department_id: int|string, instruction: string}>  $others
     * @return list<array{0: Department, 1: string}>
     */
    private function otherInstructions(array $others): array
    {
        $result = [];
        foreach ($others as $row) {
            $text = trim((string) ($row['instruction'] ?? ''));
            $department = filled($row['department_id'] ?? null) ? Department::query()->active()->find((int) $row['department_id']) : null;
            if (! $department) {
                throw new ActivityWorkflowException('Choose the department to instruct.');
            }
            if ($text === '') {
                throw new ActivityWorkflowException("Write what {$department->name} must do.");
            }
            if (isset($result[$department->id])) {
                throw new ActivityWorkflowException("{$department->name} is listed twice. Put everything it must do in one instruction.");
            }
            $result[$department->id] = [$department, mb_substr($text, 0, 2000)];
        }

        return array_values($result);
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
