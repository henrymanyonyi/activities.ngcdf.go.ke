<?php

namespace App\Livewire\Budgets;

use App\Enums\BudgetStatus;
use App\Models\BudgetAdjustment;
use App\Services\Budgeting;
use App\Support\FinancialYear;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Budgeting › Adjustments: every adjustment to the year's department budgets,
 * by status. The CEO starts on those awaiting approval; each opens on its
 * budget, where it is approved or returned.
 */
#[Layout('layouts.admin')]
#[Title('Budget adjustments')]
class Adjustments extends Component
{
    #[Url(except: '')]
    public string $fy = '';

    #[Url]
    public ?string $status = null;

    public function mount(): void
    {
        $this->fy = $this->fy ?: FinancialYear::current()->label();
        $this->status ??= Budgeting::canApprove(Auth::user()) ? BudgetStatus::Submitted->value : '';
    }

    public function render(): View
    {
        $base = fn () => BudgetAdjustment::query()->whereHas('budget', fn (Builder $b) => $b->where('financial_year', $this->fy));

        return view('livewire.budgets.adjustments', [
            'adjustments' => $base()->when($this->status, fn (Builder $q, $s) => $q->where('status', $s))
                ->with(['budget.department', 'line', 'fromLine', 'preparer', 'approver'])->latest('updated_at')->get(),
            'counts' => collect(BudgetStatus::cases())->mapWithKeys(fn (BudgetStatus $s) => [$s->value => $base()->where('status', $s->value)->count()]),
            'years' => FinancialYear::options(),
        ]);
    }
}
