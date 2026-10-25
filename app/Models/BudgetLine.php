<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FRD MD-08: a line of a department's budget for a financial year: a vote, or
 * an activity of the department's workplan with its objective, deliverables,
 * quantity, unit cost, quarters and responsible officer.
 * `proposed_amount` is what the department asked for when it last submitted;
 * `amount` is the allocation, which the CEO may change before approving and
 * which is fixed once the budget is approved. Approved adjustments change the
 * allocation during the year (App\Services\Budgeting::lineAmounts) without
 * touching `amount`. Changed only through App\Services\Budgeting.
 */
class BudgetLine extends Model
{
    use Auditable, SoftDeletes;

    /** @var array<string, string> the quarters of the July to June financial year */
    public const QUARTERS = ['Q1' => 'Q1 (Jul to Sep)', 'Q2' => 'Q2 (Oct to Dec)', 'Q3' => 'Q3 (Jan to Mar)', 'Q4' => 'Q4 (Apr to Jun)'];

    protected $fillable = [
        'department_budget_id', 'department_id', 'financial_year', 'code', 'name',
        'objective', 'deliverables', 'quantity', 'unit_cost', 'quarters', 'responsible_officer',
        'proposed_amount', 'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'proposed_amount' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'quantity' => 'decimal:2',
        ];
    }

    /** @return list<string> e.g. ['Q1', 'Q3'] */
    public function quarterList(): array
    {
        return array_values(array_filter(explode(',', (string) $this->quarters)));
    }

    /** @return BelongsTo<DepartmentBudget, $this> */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(DepartmentBudget::class, 'department_budget_id');
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return HasMany<Activity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * Lines of budgets the CEO has approved: the only ones a memo can be charged to.
     *
     * @param  Builder<self>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->whereHas('budget', fn (Builder $b) => $b->approved());
    }

    public function label(bool $withYear = true): string
    {
        return trim(($this->code ? $this->code.' · ' : '').$this->name.($withYear ? ' ('.$this->financial_year.')' : ''));
    }
}
