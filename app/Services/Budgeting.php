<?php

namespace App\Services;

use App\Enums\BudgetStatus;
use App\Exceptions\ActivityWorkflowException;
use App\Models\BudgetAdjustment;
use App\Models\BudgetLine;
use App\Models\BudgetReview;
use App\Models\DepartmentBudget;
use App\Models\User;
use App\Notifications\ActivityAlert;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of department budgets, their lines and adjustments
 * (business owner, 21 October 2026; workplans 25 October 2026):
 *
 *  - The CEO, the Chief of Staff or the Assistant prepares a department's
 *    budget for a year as a draft (`budgets.prepare`), and a Head of
 *    Department prepares their own department's workplan and budget
 *    estimates the same way (`workplans.prepare`): its objectives, and lines
 *    that are the workplan's activities with quantity, unit cost, quarters
 *    and responsible officer, typed in or imported from the template, with
 *    the workplan document attached if they have one. Only the CEO approves
 *    (`budgets.approve`). A draft the Chief of Staff or the Assistant
 *    prepared is submitted to the CEO; the CEO approves it or returns it for
 *    correction with a reason, and it is corrected and resubmitted. The CEO
 *    can approve a draft the CEO prepared without submitting it.
 *  - While a budget is with the CEO, the CEO may comment on it or on a line
 *    and change a line's amount before approving it; what the department
 *    proposed is kept on the line (`proposed_amount`). Every submission,
 *    comment, change, return and approval is kept in budget_reviews, with the
 *    lines as submitted and as approved.
 *  - Only an approved budget counts in the budget position, and only its
 *    lines can be charged by a memo.
 *  - An approved budget changes only through adjustments (supplementary,
 *    reduction, reallocation between lines), each with a reason, an effective
 *    date and the same approval; the CEO applies one directly (adjustNow).
 *    The approved allocation (`amount`) is never overwritten: each approved
 *    adjustment records the line allocation before and after it. A line never
 *    goes below zero.
 *
 * Every status change locks the row and re-checks the status, so a double
 * click or two users cannot apply a step twice. Nothing is deleted: a draft
 * is discarded (soft-deleted); changes are audited by Auditable.
 */
class Budgeting
{
    public function __construct(private Alerts $alerts, private DocumentStore $documents, private WorkplanImporter $importer) {}

    /** The Office of the CEO, for any department. */
    public static function canPrepare(?User $user): bool
    {
        return (bool) $user?->can('budgets.prepare');
    }

    /** The Office of the CEO for any department; a Head of Department for their own only. */
    public static function canPrepareFor(?User $user, ?int $departmentId): bool
    {
        if (self::canPrepare($user)) {
            return true;
        }

        return (bool) $user?->can('workplans.prepare') && $departmentId !== null && isset($user->department_id) && (int) $user->department_id === $departmentId;
    }

    /** May see the budget at all: the Office of the CEO, or the department's own Head. */
    public static function canView(?User $user, DepartmentBudget $budget): bool
    {
        return (bool) $user?->canAny(['budgets.prepare', 'budgets.approve']) || self::canPrepareFor($user, $budget->department_id);
    }

    public static function canApprove(?User $user): bool
    {
        return (bool) $user?->can('budgets.approve');
    }

    // ── Department budgets ───────────────────────────────────────────

    public function create(int $departmentId, string $financialYear, ?string $notes, User $user, ?string $objectives = null): DepartmentBudget
    {
        $this->assertPrepare($user, $departmentId);

        return DB::transaction(function () use ($departmentId, $financialYear, $notes, $user, $objectives) {
            $exists = DepartmentBudget::query()->where('department_id', $departmentId)->where('financial_year', $financialYear)->lockForUpdate()->exists();
            if ($exists) {
                throw new ActivityWorkflowException("This department already has a budget for FY {$financialYear}. Open it to change its lines, or propose an adjustment if it is approved.");
            }

            $budget = new DepartmentBudget(['department_id' => $departmentId, 'financial_year' => $financialYear, 'objectives' => $objectives ?: null, 'notes' => $notes ?: null, 'prepared_by' => $user->id]);
            $budget->forceFill(['status' => BudgetStatus::Draft])->save();

            return $budget;
        });
    }

    public function updateNotes(DepartmentBudget $budget, ?string $notes, User $user, ?string $objectives = null): void
    {
        $this->assertEditable($budget, $user);
        $budget->update(['notes' => $notes ?: null, 'objectives' => $objectives ?: null]);
    }

    /**
     * A workplan activity or vote. The amount is the quantity times the unit cost when both are given
     * and no amount is typed; otherwise the amount typed.
     *
     * @param  array{code?: ?string, name: string, amount?: ?string, objective?: ?string, deliverables?: ?string, quantity?: ?string, unit_cost?: ?string, quarters?: list<string>|string|null, responsible_officer?: ?string}  $data
     */
    public function saveLine(DepartmentBudget $budget, array $data, User $user, ?BudgetLine $line = null): BudgetLine
    {
        $this->assertEditable($budget, $user);
        if ($line && $line->department_budget_id !== $budget->id) {
            throw new ActivityWorkflowException('That line is not on this budget.');
        }

        $attributes = $this->lineAttributes($data);

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

    /**
     * Adds the lines of a filled-in workplan template to a draft, or updates the line with the same code
     * and name. Every row is checked first; if any is wrong nothing is saved. The file is kept as the
     * budget's workplan document.
     *
     * @return array{added: int, updated: int, errors: list<string>}
     */
    public function importLines(DepartmentBudget $budget, UploadedFile $file, User $user): array
    {
        $this->assertEditable($budget, $user);
        $parsed = $this->importer->parse($file->getRealPath(), $file->getClientOriginalExtension());
        if ($parsed['errors'] !== []) {
            return ['added' => 0, 'updated' => 0, 'errors' => $parsed['errors']];
        }

        $added = 0;
        $updated = 0;
        DB::transaction(function () use ($budget, $file, $user, $parsed, &$added, &$updated) {
            foreach ($parsed['rows'] as $row) {
                $existing = $budget->lines()->where('name', $row['name'])
                    ->when($row['code'] === null, fn ($q) => $q->whereNull('code'), fn ($q) => $q->where('code', $row['code']))->first();
                $this->saveLine($budget, $row, $user, $existing);
                $existing ? $updated++ : $added++;
            }
            $this->storeWorkplan($budget, $file);
        });

        return ['added' => $added, 'updated' => $updated, 'errors' => []];
    }

    /** The department's workplan document or spreadsheet, replacing any before it (the earlier one stays in the audit trail). */
    public function attachWorkplan(DepartmentBudget $budget, UploadedFile $file, User $user): void
    {
        $this->assertEditable($budget, $user);
        $this->storeWorkplan($budget, $file);
    }

    // ── The CEO's review before approval ─────────────────────────────

    /** A comment on the submission or one of its lines, kept in its history and shown to the department. */
    public function comment(DepartmentBudget $budget, string $comment, User $user, ?BudgetLine $line = null): void
    {
        $this->assertReviewing($budget, $user, $line);
        if (trim($comment) === '') {
            throw new ActivityWorkflowException('Type the comment.');
        }

        $this->record($budget, BudgetReview::COMMENT, $user, trim($comment), $line);
    }

    /**
     * The CEO changes a line's amount before approving. What the department proposed stays on the line;
     * the change and its reason go in the history.
     */
    public function reviseLine(BudgetLine $line, string $amount, ?string $comment, User $user): void
    {
        $budget = $line->budget;
        $this->assertReviewing($budget, $user, $line);
        $cents = Money::toCents($amount);
        if ($cents < 0) {
            throw new ActivityWorkflowException('Enter an amount of zero or more.');
        }

        DB::transaction(function () use ($budget, $line, $cents, $comment, $user) {
            $this->lockAt($budget, [BudgetStatus::Submitted], 'Only a budget awaiting your approval can be changed this way.');
            $before = Money::toCents($line->amount);
            if ($before === $cents) {
                return;
            }

            $line->update(['amount' => Money::fromCents($cents)]);
            $this->record($budget, BudgetReview::AMOUNT_CHANGED, $user, filled($comment) ? trim($comment) : null, $line, $before, $cents);
        });
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
            'effective_on' => filled($data['effective_on'] ?? null) ? $data['effective_on'] : null,
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

    /**
     * The CEO increases or reduces a line (or moves money between two) of an approved budget at once:
     * saved and approved in one step, with the reason, the effective date and the allocations before and after.
     *
     * @param  array{kind: string, budget_line_id?: int|string|null, from_budget_line_id?: int|string|null, new_line_code?: ?string, new_line_name?: ?string, amount: string, reason: string, effective_on?: ?string}  $data
     */
    public function adjustNow(DepartmentBudget $budget, array $data, User $user): BudgetAdjustment
    {
        if (! self::canApprove($user)) {
            throw new AuthorizationException('Only the CEO adjusts an approved budget directly. Propose the adjustment instead.');
        }

        return DB::transaction(function () use ($budget, $data, $user) {
            $adjustment = $this->saveAdjustment($budget, $data + ['effective_on' => today()->toDateString()], $user);
            $this->approve($adjustment, $user);

            return $adjustment->fresh();
        });
    }

    // ── Approval, for budgets and adjustments alike ──────────────────

    public function submit(DepartmentBudget|BudgetAdjustment $item, User $user): void
    {
        $this->assertPrepare($user, $item instanceof DepartmentBudget ? $item->department_id : null);

        DB::transaction(function () use ($item, $user) {
            $this->lockAt($item, [BudgetStatus::Draft, BudgetStatus::Returned]);
            $this->assertComplete($item);
            $resubmitted = $item->status === BudgetStatus::Returned;

            $item->forceFill(['status' => BudgetStatus::Submitted, 'submitted_by' => $user->id, 'submitted_at' => now()])->save();
            if ($item instanceof DepartmentBudget) {
                // What the department asks for now; the CEO may change the allocation before approving.
                $item->lines()->get()->each(fn (BudgetLine $l) => $l->update(['proposed_amount' => $l->amount]));
                $this->record($item, $resubmitted ? BudgetReview::RESUBMITTED : BudgetReview::SUBMITTED, $user, snapshot: $this->snapshot($item));
            }

            $what = $item instanceof DepartmentBudget ? 'A department budget' : 'A budget adjustment';
            $this->alerts->toCeo(ActivityAlert::budget(($resubmitted ? 'Corrected and resubmitted for your approval: ' : 'Awaiting your approval: ').$this->describe($item), $this->url($item), 'fa-wallet')
                ->needsAction(null, ($item instanceof DepartmentBudget ? 'Department budget' : 'Budget adjustment').' awaiting your approval', $what.($resubmitted ? ' has been corrected and resubmitted for your approval.' : ' has been submitted for your approval.'), 'Please approve it or return it for correction.'));
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

            if ($item instanceof BudgetAdjustment) {
                // The allocations it changes, before and after, so the history reads on its own.
                $now = $this->lineAmounts($item->budget);
                $cents = Money::toCents($item->amount);
                $to = $now[$item->budget_line_id]['current'] ?? 0;
                $item->forceFill([
                    'effective_on' => $item->effective_on ?? today(),
                    'line_before' => Money::fromCents($to),
                    'line_after' => Money::fromCents($to + ($item->kind === BudgetAdjustment::KIND_REDUCTION ? -$cents : $cents)),
                ]);
                if ($item->kind === BudgetAdjustment::KIND_REALLOCATION) {
                    $from = $now[$item->from_budget_line_id]['current'] ?? 0;
                    $item->forceFill(['from_line_before' => Money::fromCents($from), 'from_line_after' => Money::fromCents($from - $cents)]);
                }
            }

            $item->forceFill(['status' => BudgetStatus::Approved, 'approved_by' => $user->id, 'approved_at' => now()])->save();

            if ($item instanceof DepartmentBudget) {
                $this->record($item, BudgetReview::APPROVED, $user, snapshot: $this->snapshot($item));
            }

            $this->notifyPreparers($item, $user, ActivityAlert::budget('Approved by the CEO: '.$this->describe($item), $this->url($item), 'fa-circle-check'));
            $this->notifyDepartmentHead($item, $user, ActivityAlert::budget(($item instanceof DepartmentBudget ? 'Workplan and budget approved by the CEO: ' : 'Budget adjusted by the CEO: ').$this->describe($item), $this->url($item), 'fa-circle-check'));
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
            if ($item instanceof DepartmentBudget) {
                $this->record($item, BudgetReview::RETURNED, $user, trim($reason));
            }

            $this->notifyPreparers($item, $user, ActivityAlert::budget('Returned for correction, please revise and resubmit: '.$this->describe($item), $this->url($item), 'fa-reply')
                ->needsAction(null, 'Returned by the CEO for correction', 'The CEO has returned a '.($item instanceof DepartmentBudget ? 'department budget' : 'budget adjustment').' you prepared for correction.', 'Please correct it and resubmit it to the CEO.'));
        });
    }

    /** A draft or returned budget or adjustment that is no longer wanted. Kept, soft-deleted. */
    public function discard(DepartmentBudget|BudgetAdjustment $item, User $user): void
    {
        $this->assertPrepare($user, $item instanceof DepartmentBudget ? $item->department_id : null);

        DB::transaction(function () use ($item) {
            $this->lockAt($item, [BudgetStatus::Draft, BudgetStatus::Returned], 'Only a draft or a returned budget or adjustment can be discarded.');
            $item->delete();
        });
    }

    // ── Figures ──────────────────────────────────────────────────────

    /**
     * Each line, in cents: what the department proposed, the allocation as approved, the increases and
     * reductions approved since (a reallocation counts as an increase on one line and a reduction on the
     * other), their net effect, and the allocation now. Current = original + increases - decreases.
     *
     * @return array<int, array{proposed: ?int, original: int, increases: int, decreases: int, adjustments: int, current: int}>
     */
    public function lineAmounts(DepartmentBudget $budget): array
    {
        $amounts = $budget->lines()->get()->mapWithKeys(fn (BudgetLine $l) => [$l->id => [
            'proposed' => $l->proposed_amount !== null ? Money::toCents($l->proposed_amount) : null,
            'original' => Money::toCents($l->amount),
            'increases' => 0,
            'decreases' => 0,
        ]])->all();

        $budget->adjustments()->where('status', BudgetStatus::Approved->value)->get()
            ->each(function (BudgetAdjustment $a) use (&$amounts): void {
                $cents = Money::toCents($a->amount);
                if (isset($amounts[$a->budget_line_id])) {
                    $amounts[$a->budget_line_id][$a->kind === BudgetAdjustment::KIND_REDUCTION ? 'decreases' : 'increases'] += $cents;
                }
                if ($a->kind === BudgetAdjustment::KIND_REALLOCATION && isset($amounts[$a->from_budget_line_id])) {
                    $amounts[$a->from_budget_line_id]['decreases'] += $cents;
                }
            });

        return array_map(fn (array $a) => $a + [
            'adjustments' => $a['increases'] - $a['decreases'],
            'current' => $a['original'] + $a['increases'] - $a['decreases'],
        ], $amounts);
    }

    /**
     * The budget's totals in cents: proposed, as approved, approved increases and reductions, their net
     * effect, now, and what adjustments awaiting approval would add or take off. Reallocations add as
     * much to one line as they take off another, so the totals count them on both sides.
     *
     * @return array{proposed: int, original: int, increases: int, decreases: int, adjustments: int, current: int, pending: int}
     */
    public function totals(DepartmentBudget $budget): array
    {
        $lines = $this->lineAmounts($budget);
        $pending = $budget->adjustments()->where('status', BudgetStatus::Submitted->value)->get()->sum(fn (BudgetAdjustment $a) => $a->netCents());

        return [
            'proposed' => (int) array_sum(array_map(fn (array $l) => $l['proposed'] ?? $l['original'], $lines)),
            'original' => array_sum(array_column($lines, 'original')),
            'increases' => array_sum(array_column($lines, 'increases')),
            'decreases' => array_sum(array_column($lines, 'decreases')),
            'adjustments' => array_sum(array_column($lines, 'adjustments')),
            'current' => array_sum(array_column($lines, 'current')),
            'pending' => (int) $pending,
        ];
    }

    // ── Guards ───────────────────────────────────────────────────────

    /** The Office of the CEO, or (for a department's own budget) its Head. Adjustments: the Office of the CEO only. */
    private function assertPrepare(User $user, ?int $departmentId = null): void
    {
        if (! self::canPrepareFor($user, $departmentId)) {
            throw new AuthorizationException($user->can('workplans.prepare')
                ? 'You can prepare only your own department\'s workplan and budget.'
                : 'Only the CEO, the Chief of Staff, the Assistant Chief of Staff or the department\'s Head prepares budgets.');
        }
    }

    private function assertReviewing(DepartmentBudget $budget, User $user, ?BudgetLine $line): void
    {
        if (! self::canApprove($user)) {
            throw new AuthorizationException('Only the CEO reviews workplans and budgets.');
        }
        if ($budget->status !== BudgetStatus::Submitted) {
            throw new ActivityWorkflowException('Only a workplan and budget awaiting your approval can be reviewed this way.');
        }
        if ($line && $line->department_budget_id !== $budget->id) {
            throw new ActivityWorkflowException('That line is not on this budget.');
        }
    }

    private function assertEditable(DepartmentBudget $budget, User $user): void
    {
        $this->assertPrepare($user, $budget->department_id);
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

    /** @param  array<string, mixed>  $data */
    private function lineAttributes(array $data): array
    {
        $quantity = filled($data['quantity'] ?? null) ? (string) $data['quantity'] : null;
        $unit = filled($data['unit_cost'] ?? null) ? Money::toCents((string) $data['unit_cost']) : null;
        if ($quantity !== null && (! is_numeric(str_replace(',', '', $quantity)) || (float) str_replace(',', '', $quantity) <= 0)) {
            throw new ActivityWorkflowException('Enter a quantity greater than zero.');
        }
        $quantityHundredths = $quantity !== null ? (int) round((float) str_replace(',', '', $quantity) * 100) : null;

        $amount = filled($data['amount'] ?? null)
            ? Money::toCents((string) $data['amount'])
            : ($quantityHundredths !== null && $unit !== null ? intdiv($unit * $quantityHundredths + 50, 100) : 0);
        if ($amount <= 0) {
            throw new ActivityWorkflowException('Enter an amount greater than zero, or the quantity and the unit cost.');
        }

        $quarters = $data['quarters'] ?? [];
        $quarters = is_array($quarters) ? $quarters : preg_split('/[\s,;]+/', strtoupper((string) $quarters), -1, PREG_SPLIT_NO_EMPTY);
        $quarters = array_values(array_intersect(array_keys(BudgetLine::QUARTERS), array_map('strtoupper', $quarters)));

        return [
            'code' => ($data['code'] ?? null) ?: null,
            'name' => trim((string) $data['name']),
            'objective' => filled($data['objective'] ?? null) ? trim((string) $data['objective']) : null,
            'deliverables' => filled($data['deliverables'] ?? null) ? trim((string) $data['deliverables']) : null,
            'quantity' => $quantityHundredths !== null ? number_format($quantityHundredths / 100, 2, '.', '') : null,
            'unit_cost' => $unit !== null ? Money::fromCents($unit) : null,
            'quarters' => $quarters !== [] ? implode(',', $quarters) : null,
            'responsible_officer' => filled($data['responsible_officer'] ?? null) ? trim((string) $data['responsible_officer']) : null,
            'amount' => Money::fromCents($amount),
        ];
    }

    private function storeWorkplan(DepartmentBudget $budget, UploadedFile $file): void
    {
        $stored = $this->documents->storeBudgetFile($budget, $file);
        $budget->forceFill([
            'workplan_file_name' => $stored['name'],
            'workplan_file_path' => $stored['path'],
            'workplan_file_mime' => $stored['mime'],
            'workplan_file_size' => $stored['size'],
        ])->save();
    }

    /** @return array{lines: list<array<string, mixed>>, total: string} the lines as they stand, for the history */
    private function snapshot(DepartmentBudget $budget): array
    {
        $lines = $budget->lines()->get();

        return [
            'lines' => $lines->map(fn (BudgetLine $l) => [
                'id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'quarters' => $l->quarters,
                'proposed' => $l->proposed_amount, 'amount' => $l->amount,
            ])->values()->all(),
            'total' => Money::fromCents($lines->sum(fn (BudgetLine $l) => Money::toCents($l->amount))),
        ];
    }

    /** @param  array<string, mixed>|null  $snapshot */
    private function record(DepartmentBudget $budget, string $event, User $user, ?string $comment = null, ?BudgetLine $line = null, ?int $before = null, ?int $after = null, ?array $snapshot = null): BudgetReview
    {
        return BudgetReview::create([
            'department_budget_id' => $budget->id,
            'budget_line_id' => $line?->id,
            'event' => $event,
            'comment' => $comment,
            'amount_before' => $before !== null ? Money::fromCents($before) : null,
            'amount_after' => $after !== null ? Money::fromCents($after) : null,
            'snapshot' => $snapshot,
            'user_id' => $user->id,
        ]);
    }

    /** The department's own Head, told when the CEO approves its budget or changes it during the year. */
    private function notifyDepartmentHead(DepartmentBudget|BudgetAdjustment $item, User $actor, ActivityAlert $alert): void
    {
        $budget = $item instanceof DepartmentBudget ? $item : $item->budget;
        $already = array_filter([$item->prepared_by, $item->submitted_by]);
        $heads = User::query()->role(User::ROLE_HOD)->where('is_active', true)->where('department_id', $budget->department_id)
            ->whereKeyNot($actor->id)->when($already !== [], fn ($q) => $q->whereKeyNot($already))->get();

        $this->alerts->toUsers($heads, $alert);
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
