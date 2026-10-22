<?php

namespace App\Livewire\Budgets;

use App\Enums\BudgetStatus;
use App\Livewire\Concerns\RunsActions;
use App\Models\Department;
use App\Models\DepartmentBudget;
use App\Services\Budgeting;
use App\Services\BudgetPosition;
use App\Support\FinancialYear;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Budgeting › Department budgets: every department's budget for the chosen
 * financial year, its approval status and its position (allocation, spent,
 * committed, balance). The CEO, the Chief of Staff and the Assistant prepare
 * drafts here; only the CEO approves.
 */
#[Layout('layouts.admin')]
#[Title('Department budgets')]
class Index extends Component
{
    use RunsActions;

    #[Url(except: '')]
    public string $fy = '';

    #[Url(except: '')]
    public string $status = '';

    public bool $showPrepare = false;

    public string $prepareDepartment = '';

    public string $prepareYear = '';

    public string $prepareNotes = '';

    public function mount(): void
    {
        $this->fy = $this->fy ?: FinancialYear::current()->label();
    }

    public function openPrepare(?int $departmentId = null): void
    {
        $this->resetValidation();
        $this->prepareDepartment = (string) ($departmentId ?? '');
        $this->prepareYear = $this->fy;
        $this->prepareNotes = '';
        $this->showPrepare = true;
    }

    public function prepare(Budgeting $budgeting): void
    {
        $this->validate([
            'prepareDepartment' => ['required', 'exists:departments,id'],
            'prepareYear' => ['required', 'regex:/^\d{4}\/\d{2}$/'],
            'prepareNotes' => ['nullable', 'string', 'max:2000'],
        ], [], ['prepareDepartment' => 'department', 'prepareYear' => 'financial year', 'prepareNotes' => 'notes']);

        $budget = $this->attempt(fn () => $budgeting->create((int) $this->prepareDepartment, $this->prepareYear, $this->prepareNotes, Auth::user()));

        if ($budget instanceof DepartmentBudget) {
            $this->redirectRoute('budgets.show', $budget, navigate: true);
        }
    }

    public function render(BudgetPosition $position, Budgeting $budgeting): View
    {
        $fy = FinancialYear::fromLabel($this->fy);
        $budgets = DepartmentBudget::query()->where('financial_year', $fy->label())->withCount('lines')->with(['preparer'])->get()->keyBy('department_id');
        $figures = $position->forYear($fy)->keyBy(fn (array $r) => $r['department']?->id);

        $rows = Department::query()->active()->ordered()->get()
            ->merge(Department::query()->whereIn('id', $budgets->keys())->get())
            ->unique('id')
            ->map(function (Department $d) use ($budgets, $figures, $budgeting) {
                $budget = $budgets->get($d->id);

                return [
                    'department' => $d,
                    'budget' => $budget,
                    'totals' => $budget ? $budgeting->totals($budget) : null,
                    'position' => $figures->get($d->id),
                ];
            })
            ->filter(fn (array $r) => match ($this->status) {
                '' => true,
                'none' => $r['budget'] === null,
                default => $r['budget']?->status->value === $this->status,
            })
            ->values();

        $all = $budgets->values();

        return view('livewire.budgets.index', [
            'rows' => $rows,
            'years' => FinancialYear::options(),
            'totals' => $position->totals($position->forYear($fy)),
            'statusCounts' => collect(BudgetStatus::cases())->mapWithKeys(fn (BudgetStatus $s) => [$s->value => $all->where('status', $s)->count()]),
            'withoutBudget' => Department::query()->active()->whereNotIn('id', $budgets->keys())->count(),
            'departments' => Department::query()->active()->ordered()->get(['id', 'name']),
            'canPrepare' => Budgeting::canPrepare(Auth::user()),
        ]);
    }
}
