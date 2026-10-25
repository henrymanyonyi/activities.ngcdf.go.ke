<?php

namespace App\Livewire\Budgets;

use App\Enums\BudgetStatus;
use App\Livewire\Concerns\RunsActions;
use App\Models\BudgetAdjustment;
use App\Models\BudgetLine;
use App\Models\DepartmentBudget;
use App\Services\AccessLogger;
use App\Services\Budgeting;
use App\Services\BudgetPosition;
use App\Support\FinancialYear;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * One department's workplan and budget for a year: its objectives, lines
 * (workplan activities) and workplan document while it is a draft, the
 * submission to the CEO, the CEO's comments, changes of amount, approval or
 * return for adjustment, the review history, and, once approved, the
 * adjustments made during the year. The department's own Head prepares it as
 * well as the Office of the CEO. Every change goes through
 * App\Services\Budgeting, which checks the permission itself.
 */
#[Layout('layouts.admin')]
class Show extends Component
{
    use RunsActions;
    use WithFileUploads;

    public DepartmentBudget $budget;

    // Budget line
    public bool $showLine = false;

    public ?int $lineId = null;

    public string $lineCode = '';

    public string $lineName = '';

    public string $lineAmount = '';

    public string $lineObjective = '';

    public string $lineDeliverables = '';

    public string $lineQuantity = '';

    public string $lineUnitCost = '';

    /** @var list<string> */
    public array $lineQuarters = [];

    public string $lineResponsible = '';

    // Notes and objectives
    public bool $showNotes = false;

    public string $notes = '';

    public string $objectives = '';

    // Workplan document and template import
    public bool $showImport = false;

    public $importFile = null;

    /** @var list<string> */
    public array $importErrors = [];

    public $workplanFile = null;

    // The CEO's review before approval
    public bool $showRevise = false;

    public ?int $reviseLineId = null;

    public string $reviseAmount = '';

    public string $reviseComment = '';

    public bool $showComment = false;

    public ?int $commentLineId = null;

    public string $commentText = '';

    // The CEO's direct adjustment: checked first, then applied
    public bool $adjustDirect = false;

    public bool $adjConfirming = false;

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

    public string $adjEffective = '';

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

    public function mount(DepartmentBudget $budget, AccessLogger $log): void
    {
        abort_unless(Budgeting::canView(Auth::user(), $budget), 403);
        $this->budget = $budget;
        $log->log(AccessLogger::VIEW, $budget);
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
        $this->lineObjective = (string) $line?->objective;
        $this->lineDeliverables = (string) $line?->deliverables;
        $this->lineQuantity = $line?->quantity !== null ? rtrim(rtrim((string) $line->quantity, '0'), '.') : '';
        $this->lineUnitCost = $line?->unit_cost !== null ? Money::format(Money::toCents($line->unit_cost)) : '';
        $this->lineQuarters = $line?->quarterList() ?? [];
        $this->lineResponsible = (string) $line?->responsible_officer;
        $this->showLine = true;
    }

    public function saveLine(Budgeting $budgeting): void
    {
        $money = 'regex:/^\d{1,3}(,?\d{3})*(\.\d{1,2})?$/';
        $this->validate([
            'lineCode' => ['nullable', 'string', 'max:40'],
            'lineName' => ['required', 'string', 'max:255'],
            'lineAmount' => [Rule::requiredIf($this->lineQuantity === '' || $this->lineUnitCost === ''), 'nullable', $money],
            'lineObjective' => ['nullable', 'string', 'max:2000'],
            'lineDeliverables' => ['nullable', 'string', 'max:2000'],
            'lineQuantity' => ['nullable', 'numeric', 'gt:0', 'max:9999999'],
            'lineUnitCost' => ['nullable', $money],
            'lineQuarters' => ['array'],
            'lineQuarters.*' => [Rule::in(array_keys(BudgetLine::QUARTERS))],
            'lineResponsible' => ['nullable', 'string', 'max:255'],
        ], ['lineAmount.required' => 'Enter the amount, or the quantity and the unit cost.'], ['lineCode' => 'code', 'lineName' => 'activity or line', 'lineAmount' => 'amount', 'lineQuantity' => 'quantity', 'lineUnitCost' => 'unit cost']);

        $line = $this->lineId ? $this->budget->lines()->findOrFail($this->lineId) : null;
        $saved = $this->attempt(fn () => $budgeting->saveLine($this->budget->fresh(), [
            'code' => $this->lineCode,
            'name' => $this->lineName,
            'amount' => $this->lineAmount,
            'objective' => $this->lineObjective,
            'deliverables' => $this->lineDeliverables,
            'quantity' => $this->lineQuantity,
            'unit_cost' => $this->lineUnitCost,
            'quarters' => $this->lineQuarters,
            'responsible_officer' => $this->lineResponsible,
        ], Auth::user(), $line), $line ? 'Line updated.' : 'Line added.');

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
        $this->objectives = (string) $this->budget->objectives;
        $this->showNotes = true;
    }

    public function saveNotes(Budgeting $budgeting): void
    {
        $this->validate(['notes' => ['nullable', 'string', 'max:2000'], 'objectives' => ['nullable', 'string', 'max:5000']]);
        if ($this->attempt(fn () => $budgeting->updateNotes($this->budget->fresh(), $this->notes, Auth::user(), $this->objectives), 'Workplan objectives and notes saved.')) {
            $this->showNotes = false;
        }
    }

    // ── Workplan template and document ───────────────────────────────

    public function openImport(): void
    {
        $this->resetValidation();
        $this->reset(['importFile', 'importErrors']);
        $this->showImport = true;
    }

    public function import(Budgeting $budgeting): void
    {
        $this->validate(['importFile' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:'.config('activities.max_list_kb')]], [], ['importFile' => 'workplan file']);

        $result = $this->attempt(fn () => $budgeting->importLines($this->budget->fresh(), $this->importFile, Auth::user()));
        if (is_array($result)) {
            $this->importErrors = $result['errors'];
            if ($result['errors'] === []) {
                $this->showImport = false;
                $this->reset('importFile');
                $this->toast("Workplan imported: {$result['added']} added, {$result['updated']} updated. The file is kept as the workplan document.");
            }
        }
    }

    public function updatedWorkplanFile(Budgeting $budgeting): void
    {
        $this->validate(['workplanFile' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx', 'max:'.config('activities.max_document_kb')]], [], ['workplanFile' => 'workplan document']);
        if ($this->attempt(fn () => $budgeting->attachWorkplan($this->budget->fresh(), $this->workplanFile, Auth::user()), 'Workplan document attached.')) {
            $this->reset('workplanFile');
        }
    }

    // ── The CEO's review before approval ─────────────────────────────

    public function openRevise(int $id): void
    {
        $line = $this->budget->lines()->findOrFail($id);
        $this->resetValidation();
        [$this->reviseLineId, $this->reviseAmount, $this->reviseComment] = [$line->id, Money::format(Money::toCents($line->amount)), ''];
        $this->showRevise = true;
    }

    public function revise(Budgeting $budgeting): void
    {
        $this->validate([
            'reviseAmount' => ['required', 'regex:/^\d{1,3}(,?\d{3})*(\.\d{1,2})?$/'],
            'reviseComment' => ['nullable', 'string', 'max:2000'],
        ], [], ['reviseAmount' => 'amount', 'reviseComment' => 'reason']);
        $line = $this->budget->lines()->findOrFail($this->reviseLineId);

        if ($this->attempt(fn () => $budgeting->reviseLine($line, $this->reviseAmount, $this->reviseComment, Auth::user()), 'Amount changed. The department will see what it proposed and what you set.')) {
            $this->showRevise = false;
        }
    }

    public function openComment(?int $lineId = null): void
    {
        $lineId && $this->budget->lines()->findOrFail($lineId);
        $this->resetValidation();
        [$this->commentLineId, $this->commentText] = [$lineId, ''];
        $this->showComment = true;
    }

    public function saveComment(Budgeting $budgeting): void
    {
        $this->validate(['commentText' => ['required', 'string', 'max:2000']], [], ['commentText' => 'comment']);
        $line = $this->commentLineId ? $this->budget->lines()->findOrFail($this->commentLineId) : null;

        if ($this->attempt(fn () => $budgeting->comment($this->budget->fresh(), $this->commentText, Auth::user(), $line), 'Comment added to the review history.')) {
            $this->showComment = false;
        }
    }

    // ── Adjustments ──────────────────────────────────────────────────

    /** The CEO's direct adjustment: the same form, checked, then applied at once. */
    public function openAdjustNow(): void
    {
        $this->openAdjustment();
        $this->adjustDirect = true;
    }

    public function checkAdjustment(): void
    {
        $this->validateAdjustment();
        $this->adjConfirming = true;
    }

    public function applyAdjustment(Budgeting $budgeting): void
    {
        $this->validateAdjustment();
        $done = $this->attempt(fn () => $budgeting->adjustNow($this->budget->fresh(), $this->adjustmentData(), Auth::user()), 'Adjustment applied. The allocation before and after is kept in the history.');

        if ($done) {
            $this->showAdjustment = false;
        }
        $this->adjConfirming = false;
    }

    private function validateAdjustment(): void
    {
        $this->validate([
            'adjKind' => ['required', Rule::in(array_keys(BudgetAdjustment::KINDS))],
            'adjAmount' => ['required', 'regex:/^\d{1,3}(,?\d{3})*(\.\d{1,2})?$/'],
            'adjReason' => ['required', 'string', 'max:2000'],
            'adjNewCode' => ['nullable', 'string', 'max:40'],
            'adjNewName' => ['nullable', 'string', 'max:255'],
            'adjEffective' => ['required', 'date', 'after_or_equal:'.FinancialYear::fromLabel($this->budget->financial_year)->start()->toDateString(), 'before_or_equal:'.FinancialYear::fromLabel($this->budget->financial_year)->end()->toDateString()],
        ], ['adjEffective.after_or_equal' => 'The effective date must be in FY '.$this->budget->financial_year.'.', 'adjEffective.before_or_equal' => 'The effective date must be in FY '.$this->budget->financial_year.'.'], ['adjKind' => 'kind', 'adjAmount' => 'amount', 'adjReason' => 'reason', 'adjNewCode' => 'code', 'adjNewName' => 'name', 'adjEffective' => 'effective date']);
    }

    /** @return array<string, mixed> */
    private function adjustmentData(): array
    {
        return [
            'kind' => $this->adjKind,
            'budget_line_id' => $this->adjLine,
            'from_budget_line_id' => $this->adjFrom,
            'new_line_code' => $this->adjNewCode,
            'new_line_name' => $this->adjNewName,
            'amount' => $this->adjAmount,
            'reason' => $this->adjReason,
            'effective_on' => $this->adjEffective,
        ];
    }

    public function updated(string $property): void
    {
        // Any change to the adjustment form needs checking again before it is applied.
        if (str_starts_with($property, 'adj') && $property !== 'adjConfirming') {
            $this->adjConfirming = false;
        }
    }

    public function openAdjustment(?int $id = null): void
    {
        $this->resetValidation();
        $this->adjustDirect = false;
        $this->adjConfirming = false;
        $adjustment = $id ? $this->budget->adjustments()->findOrFail($id) : null;
        $this->adjustmentId = $adjustment?->id;
        $this->adjKind = $adjustment?->kind ?? BudgetAdjustment::KIND_SUPPLEMENTARY;
        $this->adjLine = (string) $adjustment?->budget_line_id;
        $this->adjFrom = (string) $adjustment?->from_budget_line_id;
        $this->adjNewCode = (string) $adjustment?->new_line_code;
        $this->adjNewName = (string) $adjustment?->new_line_name;
        $this->adjAmount = $adjustment ? Money::format(Money::toCents($adjustment->amount)) : '';
        $this->adjReason = (string) $adjustment?->reason;
        $fy = FinancialYear::fromLabel($this->budget->financial_year);
        $this->adjEffective = ($adjustment?->effective_on ?? today()->max($fy->start())->min($fy->end()))->toDateString();
        $this->showAdjustment = true;
    }

    public function saveAdjustment(Budgeting $budgeting): void
    {
        $this->validateAdjustment();

        $adjustment = $this->adjustmentId ? $this->budget->adjustments()->findOrFail($this->adjustmentId) : null;
        $saved = $this->attempt(fn () => $budgeting->saveAdjustment($this->budget->fresh(), $this->adjustmentData(), Auth::user(), $adjustment), $adjustment ? 'Adjustment updated.' : 'Adjustment saved as a draft.');

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

        // What the adjustment being checked does to the lines it touches and to the total.
        $preview = null;
        if ($this->showAdjustment && $this->adjConfirming) {
            $cents = Money::toCents($this->adjAmount ?: '0');
            $to = $amounts[(int) $this->adjLine]['current'] ?? 0;
            $preview = [
                'to' => [$to, $to + ($this->adjKind === BudgetAdjustment::KIND_REDUCTION ? -$cents : $cents)],
                'from' => $this->adjKind === BudgetAdjustment::KIND_REALLOCATION ? [($amounts[(int) $this->adjFrom]['current'] ?? 0), ($amounts[(int) $this->adjFrom]['current'] ?? 0) - $cents] : null,
                'total' => match ($this->adjKind) {
                    BudgetAdjustment::KIND_SUPPLEMENTARY => $cents,
                    BudgetAdjustment::KIND_REDUCTION => -$cents,
                    default => 0,
                },
            ];
        }

        return view('livewire.budgets.show', [
            'lines' => $lines,
            'amounts' => $amounts,
            'totals' => $budgeting->totals($this->budget),
            'adjustments' => $this->budget->adjustments()->with(['line', 'fromLine', 'preparer', 'approver', 'returner'])->get(),
            'reviews' => $this->budget->reviews()->with(['user', 'line'])->get(),
            'figures' => $figures,
            'shortfall' => $shortfall,
            'preview' => $preview,
            'ownDepartment' => ! $user->canAny(['budgets.prepare', 'budgets.approve']),
            'canPrepare' => Budgeting::canPrepareFor($user, $this->budget->department_id),
            'canPropose' => Budgeting::canPrepare($user),
            'canApprove' => Budgeting::canApprove($user),
            'confirmItem' => $this->showConfirm ? $this->item($this->confirmType, $this->confirmId) : null,
        ])->title($this->budget->title());
    }
}
