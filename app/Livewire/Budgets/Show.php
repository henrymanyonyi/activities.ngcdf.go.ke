<?php

namespace App\Livewire\Budgets;

use App\Enums\BudgetStatus;
use App\Livewire\Concerns\RunsActions;
use App\Models\BudgetAdjustment;
use App\Models\DepartmentBudget;
use App\Services\Budgeting;
use App\Services\BudgetPosition;
use App\Support\FinancialYear;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One department's budget for a year: its lines while it is a draft, the
 * submission to the CEO, the CEO's approval or return for correction, and,
 * once approved, the adjustments made during the year. Every change goes
 * through App\Services\Budgeting, which checks the permission itself.
 */
#[Layout('layouts.admin')]
class Show extends Component
{
    use RunsActions;

    public DepartmentBudget $budget;

    // Budget line
    public bool $showLine = false;

    public ?int $lineId = null;

    public string $lineCode = '';

    public string $lineName = '';

    public string $lineAmount = '';

    // Notes
    public bool $showNotes = false;

    public string $notes = '';

    // Adjustment
    public bool $showAdjustment = false;

    public ?int $adjustmentId = null;

    public string $adjKind = BudgetAdjustment::KIND_SUPPLEMENTARY;

    public string $adjLine = '';

    public string $adjFrom = '';

    public string $adjNewCode = '';

    public string $adjNewName = '';

    public string $adjAmount = '';

    public string $adjReason = '';

    // Submit, approve or discard, for the budget or one adjustment
    public bool $showConfirm = false;

    public string $confirmAction = '';

    public string $confirmType = '';

    public ?int $confirmId = null;

    // Return for correction
    public bool $showReturn = false;

    public string $returnType = '';

    public ?int $returnId = null;

    public string $returnReason = '';

    public function mount(DepartmentBudget $budget): void
    {
        abort_unless(Auth::user()->canAny(['budgets.prepare', 'budgets.approve']), 403);
        $this->budget = $budget;
    }

    // ── Lines and notes ──────────────────────────────────────────────

    public function openLine(?int $id = null): void
    {
        $this->resetValidation();
        $line = $id ? $this->budget->lines()->findOrFail($id) : null;
        $this->lineId = $line?->id;
        $this->lineCode = (string) $line?->code;
        $this->lineName = (string) $line?->name;
        $this->lineAmount = $line ? Money::format(Money::toCents($line->amount)) : '';
        $this->showLine = true;
    }

    public function saveLine(Budgeting $budgeting): void
    {
        $this->validate([
            'lineCode' => ['nullable', 'string', 'max:40'],
            'lineName' => ['required', 'string', 'max:255'],
            'lineAmount' => ['required', 'regex:/^\d{1,3}(,?\d{3})*(\.\d{1,2})?$/'],
        ], [], ['lineCode' => 'code', 'lineName' => 'name', 'lineAmount' => 'allocation']);

        $line = $this->lineId ? $this->budget->lines()->findOrFail($this->lineId) : null;
        $saved = $this->attempt(fn () => $budgeting->saveLine($this->budget->fresh(), ['code' => $this->lineCode, 'name' => $this->lineName, 'amount' => $this->lineAmount], Auth::user(), $line), $line ? 'Line updated.' : 'Line added.');

        if ($saved) {
            $this->showLine = false;
        }
    }

    public function removeLine(Budgeting $budgeting, int $id): void
    {
        $line = $this->budget->lines()->findOrFail($id);
        $this->attempt(fn () => $budgeting->removeLine($line, Auth::user()), 'Line removed.');
    }

    public function openNotes(): void
    {
        $this->notes = (string) $this->budget->notes;
        $this->showNotes = true;
    }

    public function saveNotes(Budgeting $budgeting): void
    {
        $this->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        if ($this->attempt(fn () => $budgeting->updateNotes($this->budget->fresh(), $this->notes, Auth::user()), 'Notes saved.')) {
            $this->showNotes = false;
        }
    }

    // ── Adjustments ──────────────────────────────────────────────────

    public function openAdjustment(?int $id = null): void
    {
        $this->resetValidation();
        $adjustment = $id ? $this->budget->adjustments()->findOrFail($id) : null;
        $this->adjustmentId = $adjustment?->id;
        $this->adjKind = $adjustment?->kind ?? BudgetAdjustment::KIND_SUPPLEMENTARY;
        $this->adjLine = (string) $adjustment?->budget_line_id;
        $this->adjFrom = (string) $adjustment?->from_budget_line_id;
        $this->adjNewCode = (string) $adjustment?->new_line_code;
        $this->adjNewName = (string) $adjustment?->new_line_name;
        $this->adjAmount = $adjustment ? Money::format(Money::toCents($adjustment->amount)) : '';
        $this->adjReason = (string) $adjustment?->reason;
        $this->showAdjustment = true;
    }

    public function saveAdjustment(Budgeting $budgeting): void
    {
        $this->validate([
            'adjKind' => ['required', Rule::in(array_keys(BudgetAdjustment::KINDS))],
            'adjAmount' => ['required', 'regex:/^\d{1,3}(,?\d{3})*(\.\d{1,2})?$/'],
            'adjReason' => ['required', 'string', 'max:2000'],
            'adjNewCode' => ['nullable', 'string', 'max:40'],
            'adjNewName' => ['nullable', 'string', 'max:255'],
        ], [], ['adjKind' => 'kind', 'adjAmount' => 'amount', 'adjReason' => 'reason', 'adjNewCode' => 'code', 'adjNewName' => 'name']);

        $adjustment = $this->adjustmentId ? $this->budget->adjustments()->findOrFail($this->adjustmentId) : null;
        $saved = $this->attempt(fn () => $budgeting->saveAdjustment($this->budget->fresh(), [
            'kind' => $this->adjKind,
            'budget_line_id' => $this->adjLine,
            'from_budget_line_id' => $this->adjFrom,
            'new_line_code' => $this->adjNewCode,
            'new_line_name' => $this->adjNewName,
            'amount' => $this->adjAmount,
            'reason' => $this->adjReason,
        ], Auth::user(), $adjustment), $adjustment ? 'Adjustment updated.' : 'Adjustment saved as a draft.');

        if ($saved) {
            $this->showAdjustment = false;
        }
    }

    // ── Submit, approve, discard, return ─────────────────────────────

    public function ask(string $action, string $type, ?int $id = null): void
    {
        abort_unless(in_array($action, ['submit', 'approve', 'discard'], true) && in_array($type, ['budget', 'adjustment'], true), 404);
        $this->item($type, $id);
        [$this->confirmAction, $this->confirmType, $this->confirmId] = [$action, $type, $id];
        $this->showConfirm = true;
    }

    public function confirm(Budgeting $budgeting): void
    {
        $item = $this->item($this->confirmType, $this->confirmId);
        $user = Auth::user();
        $what = $this->confirmType === 'budget' ? 'Budget' : 'Adjustment';

        $done = match ($this->confirmAction) {
            'submit' => $this->attempt(fn () => $budgeting->submit($item, $user), "{$what} submitted to the CEO for approval."),
            'approve' => $this->attempt(fn () => $budgeting->approve($item, $user), "{$what} approved."),
            'discard' => $this->attempt(fn () => $budgeting->discard($item, $user), "{$what} discarded."),
            default => null,
        };
        $this->showConfirm = false;

        if ($done && $this->confirmAction === 'discard' && $this->confirmType === 'budget') {
            $this->redirectRoute('budgets.index', ['fy' => $this->budget->financial_year], navigate: true);
        }
    }

    public function openReturn(string $type, ?int $id = null): void
    {
        abort_unless(in_array($type, ['budget', 'adjustment'], true), 404);
        $this->item($type, $id);
        $this->resetValidation();
        [$this->returnType, $this->returnId, $this->returnReason] = [$type, $id, ''];
        $this->showReturn = true;
    }

    public function sendBack(Budgeting $budgeting): void
    {
        $this->validate(['returnReason' => ['required', 'string', 'max:2000']], [], ['returnReason' => 'what needs to be corrected']);
        $item = $this->item($this->returnType, $this->returnId);

        if ($this->attempt(fn () => $budgeting->returnForCorrection($item, $this->returnReason, Auth::user()), 'Returned for correction. The preparer has been alerted.')) {
            $this->showReturn = false;
        }
    }

    private function item(string $type, ?int $id): DepartmentBudget|BudgetAdjustment
    {
        return $type === 'budget' ? $this->budget->fresh() : $this->budget->adjustments()->findOrFail($id);
    }

    public function render(Budgeting $budgeting, BudgetPosition $position): View
    {
        $this->budget->refresh()->load(['department', 'preparer', 'submitter', 'approver', 'returner']);
        $user = Auth::user();
        $lines = $this->budget->lines()->get();
        $amounts = $budgeting->lineAmounts($this->budget);
        $approved = $this->budget->status === BudgetStatus::Approved;
        $figures = $approved ? $position->forDepartment(FinancialYear::fromLabel($this->budget->financial_year), $this->budget->department_id) : null;

        // What a reduction being drafted would leave against what is already spent and committed.
        $shortfall = null;
        if ($this->showAdjustment && $figures && $this->adjKind === BudgetAdjustment::KIND_REDUCTION && $figures['balance'] !== null) {
            $after = $figures['balance'] - Money::toCents($this->adjAmount ?: '0');
            $shortfall = $after < 0 ? -$after : null;
        }

        return view('livewire.budgets.show', [
            'lines' => $lines,
            'amounts' => $amounts,
            'totals' => $budgeting->totals($this->budget),
            'adjustments' => $this->budget->adjustments()->with(['line', 'fromLine', 'preparer', 'approver', 'returner'])->get(),
            'figures' => $figures,
            'shortfall' => $shortfall,
            'canPrepare' => Budgeting::canPrepare($user),
            'canApprove' => Budgeting::canApprove($user),
            'confirmItem' => $this->showConfirm ? $this->item($this->confirmType, $this->confirmId) : null,
        ])->title($this->budget->title());
    }
}
