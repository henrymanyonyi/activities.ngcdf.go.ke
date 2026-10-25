<?php

namespace App\Services;

use App\Enums\BudgetStatus;
use App\Exceptions\ActivityWorkflowException;
use App\Models\BudgetAdjustment;
use App\Models\BudgetLine;
use App\Models\DepartmentBudget;
use App\Models\User;
use App\Notifications\ActivityAlert;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of department budgets, their lines and adjustments
 * (business owner, 21 October 2026):
 *
 *  - The CEO, the Chief of Staff or the Assistant prepares a department's
 *    budget for a year as a draft (`budgets.prepare`). Only the CEO approves
 *    (`budgets.approve`). A draft the Chief of Staff or the Assistant
 *    prepared is submitted to the CEO; the CEO approves it or returns it for
 *    correction with a reason, and it is corrected and resubmitted. The CEO
 *    can approve a draft the CEO prepared without submitting it.
 *  - Only an approved budget counts in the budget position, and only its
 *    lines can be charged by a memo.
 *  - An approved budget changes only through adjustments (supplementary,
 *    reduction, reallocation between lines), each with a reason and the same
 *    approval. A line never goes below zero.
 *
 * Every status change locks the row and re-checks the status, so a double
 * click or two users cannot apply a step twice. Nothing is deleted: a draft
 * is discarded (soft-deleted); changes are audited by Auditable.
 */
class Budgeting
{
    public function __construct(private Alerts $alerts) {}

    public static function canPrepare(?User $user): bool
    {
        return (bool) $user?->can('budgets.prepare');
    }

    public static function canApprove(?User $user): bool
    {
        return (bool) $user?->can('budgets.approve');
    }

    // ── Department budgets ───────────────────────────────────────────

    public function create(int $departmentId, string $financialYear, ?string $notes, User $user): DepartmentBudget
    {
        $this->assertPrepare($user);

        return DB::transaction(function () use ($departmentId, $financialYear, $notes, $user) {
            $exists = DepartmentBudget::query()->where('department_id', $departmentId)->where('financial_year', $financialYear)->lockForUpdate()->exists();
            if ($exists) {
                throw new ActivityWorkflowException("This department already has a budget for FY {$financialYear}. Open it to change its lines, or propose an adjustment if it is approved.");
            }

            $budget = new DepartmentBudget(['department_id' => $departmentId, 'financial_year' => $financialYear, 'notes' => $notes ?: null, 'prepared_by' => $user->id]);
            $budget->forceFill(['status' => BudgetStatus::Draft])->save();

            return $budget;
        });
    }

    public function updateNotes(DepartmentBudget $budget, ?string $notes, User $user): void
    {
        $this->assertEditable($budget, $user);
        $budget->update(['notes' => $notes ?: null]);
    }

    /** @param  array{code?: ?string, name: string, amount: string}  $data */
    public function saveLine(DepartmentBudget $budget, array $data, User $user, ?BudgetLine $line = null): BudgetLine
    {
        $this->assertEditable($budget, $user);
        if ($line && $line->department_budget_id !== $budget->id) {
            throw new ActivityWorkflowException('That line is not on this budget.');
        }

        $amount = Money::toCents($data['amount']);
        if ($amount <= 0) {
            throw new ActivityWorkflowException('Enter an allocation greater than zero.');
        }

        $attributes = [
            'code' => ($data['code'] ?? null) ?: null,
            'name' => $data['name'],
            'amount' => Money::fromCents($amount),
        ];

        if ($line) {
            $line->update($attributes);

            return $line;
        }

        return $budget->lines()->create($attributes + ['department_id' => $budget->department_id, 'financial_year' => $budget->financial_year]);
    }

    public function removeLine(BudgetLine $line, User $user): void
    {
        $this->assertEditable($line->budget, $user);
        $line->delete();
    }

    // ── Adjustments ──────────────────────────────────────────────────

    /**
     * A new adjustment, or a change to one still in draft or returned.
     *
     * @param  array{kind: string, budget_line_id?: int|string|null, from_budget_line_id?: int|string|null, new_line_code?: ?string, new_line_name?: ?string, amount: string, reason: string}  $data
     */
    public function saveAdjustment(DepartmentBudget $budget, array $data, User $user, ?BudgetAdjustment $adjustment = null): BudgetAdjustment
    {
        $this->assertPrepare($user);
        if ($budget->status !== BudgetStatus::Approved) {
            throw new ActivityWorkflowException('Only an approved budget can be adjusted. Change the lines of a draft directly.');
        }
        if ($adjustment && (! $adjustment->status->isEditable() || $adjustment->department_budget_id !== $budget->id)) {
            throw new ActivityWorkflowException('This adjustment can no longer be changed.');
        }

        $kind = $data['kind'];
        $lineIds = $budget->lines()->pluck('id')->all();
        $to = filled($data['budget_line_id'] ?? null) ? (int) $data['budget_line_id'] : null;
        $from = $kind === BudgetAdjustment::KIND_REALLOCATION && filled($data['from_budget_line_id'] ?? null) ? (int) $data['from_budget_line_id'] : null;
        $newName = $kind === BudgetAdjustment::KIND_SUPPLEMENTARY && $to === null ? trim((string) ($data['new_line_name'] ?? '')) : '';

        $amount = Money::toCents($data['amount']);
        $problem = match (true) {
            ! array_key_exists($kind, BudgetAdjustment::KINDS) => 'Choose the kind of adjustment.',
            $amount <= 0 => 'Enter an amount greater than zero.',
            trim($data['reason']) === '' => 'Give the reason for the adjustment.',
            $to !== null && ! in_array($to, $lineIds, true) => 'That line is not on this budget.',
            $from !== null && ! in_array($from, $lineIds, true) => 'That line is not on this budget.',
            $kind === BudgetAdjustment::KIND_SUPPLEMENTARY && $to === null && $newName === '' => 'Choose the line to increase, or name the new line.',
            $kind === BudgetAdjustment::KIND_REDUCTION && $to === null => 'Choose the line to reduce.',
            $kind === BudgetAdjustment::KIND_REALLOCATION && ($from === null || $to === null) => 'Choose the line the money comes from and the line it goes to.',
            $kind === BudgetAdjustment::KIND_REALLOCATION && $from === $to => 'Choose two different lines.',
            default => null,
        };
        if ($problem) {
            throw new ActivityWorkflowException($problem);
        }

        $attributes = [
            'kind' => $kind,
            'budget_line_id' => $to,
            'from_budget_line_id' => $from,
            'new_line_code' => $newName !== '' ? (($data['new_line_code'] ?? null) ?: null) : null,
            'new_line_name' => $newName !== '' ? $newName : null,
            'amount' => Money::fromCents($amount),
            'reason' => trim($data['reason']),
        ];
        $this->assertLineCovers($budget, $attributes);

        if ($adjustment) {
            $adjustment->update($attributes);

            return $adjustment;
        }

        $adjustment = new BudgetAdjustment($attributes + ['department_budget_id' => $budget->id, 'prepared_by' => $user->id]);
        $adjustment->forceFill(['status' => BudgetStatus::Draft])->save();

        return $adjustment;
    }

    // ── Approval, for budgets and adjustments alike ──────────────────

    public function submit(DepartmentBudget|BudgetAdjustment $item, User $user): void
    {
        $this->assertPrepare($user);

        DB::transaction(function () use ($item, $user) {
            $this->lockAt($item, [BudgetStatus::Draft, BudgetStatus::Returned]);
            $this->assertComplete($item);
            $resubmitted = $item->status === BudgetStatus::Returned;

            $item->forceFill(['status' => BudgetStatus::Submitted, 'submitted_by' => $user->id, 'submitted_at' => now()])->save();

            $this->alerts->toCeo(ActivityAlert::budget(($resubmitted ? 'Corrected and resubmitted for your approval: ' : 'Awaiting your approval: ').$this->describe($item), $this->url($item), 'fa-wallet'));
        });
    }

    public function approve(DepartmentBudget|BudgetAdjustment $item, User $user): void
    {
        if (! self::canApprove($user)) {
            throw new AuthorizationException('Only the CEO approves budgets and budget adjustments.');
        }

        DB::transaction(function () use ($item, $user) {
            // A draft the CEO prepared needs no submission to the CEO.
            $own = $item->prepared_by === $user->id ? [BudgetStatus::Draft] : [];
            if ($item instanceof BudgetAdjustment) {
                // One adjustment to a budget at a time, so two cannot both take the same money off a line.
                DepartmentBudget::query()->whereKey($item->department_budget_id)->lockForUpdate()->value('id');
            }
            $this->lockAt($item, [BudgetStatus::Submitted, ...$own], 'Only a budget or adjustment awaiting your approval can be approved. A draft prepared by the Chief of Staff or the Assistant is submitted to you first.');
            $this->assertComplete($item);

            if ($item instanceof BudgetAdjustment && $item->new_line_name !== null && $item->budget_line_id === null) {
                $line = $item->budget->lines()->create([
                    'department_id' => $item->budget->department_id,
                    'financial_year' => $item->budget->financial_year,
                    'code' => $item->new_line_code,
                    'name' => $item->new_line_name,
                    'amount' => '0.00',
                ]);
                $item->forceFill(['budget_line_id' => $line->id]);
            }

            $item->forceFill(['status' => BudgetStatus::Approved, 'approved_by' => $user->id, 'approved_at' => now()])->save();

            $this->notifyPreparers($item, $user, ActivityAlert::budget('Approved by the CEO: '.$this->describe($item), $this->url($item), 'fa-circle-check'));
        });
    }

    public function returnForCorrection(DepartmentBudget|BudgetAdjustment $item, string $reason, User $user): void
    {
        if (! self::canApprove($user)) {
            throw new AuthorizationException('Only the CEO returns budgets and budget adjustments.');
        }
        if (trim($reason) === '') {
            throw new ActivityWorkflowException('Say what needs to be corrected.');
        }

        DB::transaction(function () use ($item, $reason, $user) {
            $this->lockAt($item, [BudgetStatus::Submitted], 'Only a budget or adjustment awaiting your approval can be returned.');

            $item->forceFill(['status' => BudgetStatus::Returned, 'returned_by' => $user->id, 'returned_at' => now(), 'return_reason' => trim($reason)])->save();

            $this->notifyPreparers($item, $user, ActivityAlert::budget('Returned for correction, please revise and resubmit: '.$this->describe($item), $this->url($item), 'fa-reply')
                ->needsAction(null, 'Returned by the CEO for correction', 'The CEO has returned a '.($item instanceof DepartmentBudget ? 'department budget' : 'budget adjustment').' you prepared for correction.', 'Please correct it and resubmit it to the CEO.'));
        });
    }

    /** A draft or returned budget or adjustment that is no longer wanted. Kept, soft-deleted. */
    public function discard(DepartmentBudget|BudgetAdjustment $item, User $user): void
    {
        $this->assertPrepare($user);

        DB::transaction(function () use ($item) {
            $this->lockAt($item, [BudgetStatus::Draft, BudgetStatus::Returned], 'Only a draft or a returned budget or adjustment can be discarded.');
            $item->delete();
        });
    }

    // ── Figures ──────────────────────────────────────────────────────

    /**
     * Each line's allocation as approved, the approved adjustments to it, and
     * the allocation now, in cents.
     *
     * @return array<int, array{original: int, adjustments: int, current: int}>
     */
    public function lineAmounts(DepartmentBudget $budget): array
    {
        $amounts = $budget->lines()->get()->mapWithKeys(fn (BudgetLine $l) => [$l->id => ['original' => Money::toCents($l->amount), 'adjustments' => 0]])->all();

        $budget->adjustments()->where('status', BudgetStatus::Approved->value)->get()
            ->each(function (BudgetAdjustment $a) use (&$amounts): void {
                $cents = Money::toCents($a->amount);
                if (isset($amounts[$a->budget_line_id])) {
                    $amounts[$a->budget_line_id]['adjustments'] += $a->kind === BudgetAdjustment::KIND_REDUCTION ? -$cents : $cents;
                }
                if ($a->kind === BudgetAdjustment::KIND_REALLOCATION && isset($amounts[$a->from_budget_line_id])) {
                    $amounts[$a->from_budget_line_id]['adjustments'] -= $cents;
                }
            });

        return array_map(fn (array $a) => $a + ['current' => $a['original'] + $a['adjustments']], $amounts);
    }

    /**
     * The budget's totals: as approved, approved adjustments, now, and what
     * adjustments awaiting approval would add or take off.
     *
     * @return array{original: int, adjustments: int, current: int, pending: int}
     */
    public function totals(DepartmentBudget $budget): array
    {
        $lines = $this->lineAmounts($budget);
        $pending = $budget->adjustments()->where('status', BudgetStatus::Submitted->value)->get()->sum(fn (BudgetAdjustment $a) => $a->netCents());

        return [
            'original' => array_sum(array_column($lines, 'original')),
            'adjustments' => array_sum(array_column($lines, 'adjustments')),
            'current' => array_sum(array_column($lines, 'current')),
            'pending' => (int) $pending,
        ];
    }

    // ── Guards ───────────────────────────────────────────────────────

    private function assertPrepare(User $user): void
    {
        if (! self::canPrepare($user)) {
            throw new AuthorizationException('Only the CEO, the Chief of Staff or the Assistant Chief of Staff prepares budgets.');
        }
    }

    private function assertEditable(DepartmentBudget $budget, User $user): void
    {
        $this->assertPrepare($user);
        if (! $budget->status->isEditable()) {
            throw new ActivityWorkflowException($budget->status === BudgetStatus::Approved
                ? 'This budget is approved. Propose an adjustment to change it.'
                : 'This budget is with the CEO for approval and cannot be changed until the CEO approves or returns it.');
        }
    }

    private function assertComplete(DepartmentBudget|BudgetAdjustment $item): void
    {
        if ($item instanceof DepartmentBudget) {
            if (! $item->lines()->exists()) {
                throw new ActivityWorkflowException('Add at least one budget line first.');
            }

            return;
        }

        if ($item->budget->status !== BudgetStatus::Approved) {
            throw new ActivityWorkflowException('The budget this adjusts is not approved.');
        }
        $this->assertLineCovers($item->budget, $item->only(['kind', 'budget_line_id', 'from_budget_line_id', 'amount']));
    }

    /**
     * A reduction or reallocation may not take a line below zero, at the
     * allocation approved so far.
     *
     * @param  array<string, mixed>  $adjustment
     */
    private function assertLineCovers(DepartmentBudget $budget, array $adjustment): void
    {
        $lineId = match ($adjustment['kind']) {
            BudgetAdjustment::KIND_REDUCTION => $adjustment['budget_line_id'],
            BudgetAdjustment::KIND_REALLOCATION => $adjustment['from_budget_line_id'],
            default => null,
        };
        if ($lineId === null) {
            return;
        }

        $available = $this->lineAmounts($budget)[$lineId]['current'] ?? 0;
        if (Money::toCents($adjustment['amount']) > $available) {
            throw new ActivityWorkflowException('The line has only KES '.Money::format($available).' to take off. Enter that or less.');
        }
    }

    /** @param  list<BudgetStatus>  $allowed */
    private function lockAt(DepartmentBudget|BudgetAdjustment $item, array $allowed, ?string $message = null): void
    {
        $current = $item::query()->whereKey($item->id)->lockForUpdate()->toBase()->value('status');

        if (! in_array($current, array_map(fn (BudgetStatus $s) => $s->value, $allowed), true)) {
            throw new ActivityWorkflowException($current === $item->getRawOriginal('status')
                ? ($message ?? 'This cannot be done at its current stage.')
                : 'Someone else changed this a moment ago. Reload the page to see where it is now.');
        }
    }

    private function notifyPreparers(DepartmentBudget|BudgetAdjustment $item, User $actor, ActivityAlert $alert): void
    {
        $this->alerts->toUsers(
            User::query()->whereKey(array_filter([$item->prepared_by, $item->submitted_by]))->whereKeyNot($actor->id)->get(),
            $alert,
        );
    }

    private function describe(DepartmentBudget|BudgetAdjustment $item): string
    {
        return $item instanceof DepartmentBudget
            ? $item->title()
            : 'adjustment to the '.$item->budget->title().' ('.$item->summary().')';
    }

    private function url(DepartmentBudget|BudgetAdjustment $item): string
    {
        return route('budgets.show', $item instanceof DepartmentBudget ? $item : $item->department_budget_id);
    }
}
