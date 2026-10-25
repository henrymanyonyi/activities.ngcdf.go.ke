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
 * drafts here; only the CEO approves. A Head of Department sees only their
 * own department here (Workplan and budget) and prepares its workplan.
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
        abort_unless(Auth::user()->canAny(['budgets.prepare', 'budgets.approve', 'workplans.prepare']), 403);
        $this->fy = $this->fy ?: FinancialYear::current()->label();
    }

    /** A Head of Department's own department; null for the Office of the CEO, who see every department. */
    private function ownDepartment(): ?int
    {
        $user = Auth::user();

        return $user->canAny(['budgets.prepare', 'budgets.approve']) ? null : (int) $user->department_id;
    }

    public function openPrepare(?int $departmentId = null): void
    {
        $this->resetValidation();
        $this->prepareDepartment = (string) ($this->ownDepartment() ?? $departmentId ?? '');
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

        $budget = $this->attempt(fn () => $budgeting->create($this->ownDepartment() ?? (int) $this->prepareDepartment, $this->prepareYear, $this->prepareNotes, Auth::user()));

        if ($budget instanceof DepartmentBudget) {
            $this->redirectRoute('budgets.show', $budget, navigate: true);
        }
    }

    public function render(BudgetPosition $position, Budgeting $budgeting): View
    {
        $fy = FinancialYear::fromLabel($this->fy);
        $own = $this->ownDepartment();
        $budgets = DepartmentBudget::query()->where('financial_year', $fy->label())->when($own, fn ($q, $id) => $q->where('department_id', $id))
            ->withCount('lines')->with(['preparer'])->get()->keyBy('department_id');
        $yearFigures = $position->forYear($fy)->when($own, fn ($rows, $id) => $rows->filter(fn (array $r) => $r['department']?->id === $id));
        $figures = $yearFigures->keyBy(fn (array $r) => $r['department']?->id);

        $rows = Department::query()->active()->when($own, fn ($q, $id) => $q->whereKey($id))->ordered()->get()
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
            'totals' => $position->totals($yearFigures),
            'statusCounts' => collect(BudgetStatus::cases())->mapWithKeys(fn (BudgetStatus $s) => [$s->value => $all->where('status', $s)->count()]),
            'withoutBudget' => Department::query()->active()->whereNotIn('id', $budgets->keys())->count(),
            'departments' => Department::query()->active()->ordered()->get(['id', 'name']),
            'canPrepare' => Budgeting::canPrepare(Auth::user()) || ($own && Budgeting::canPrepareFor(Auth::user(), $own)),
            'ownDepartment' => $own,
        ]);
    }
}
