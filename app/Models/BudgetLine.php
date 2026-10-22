<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FRD MD-08: a line (vote) of a department's budget for a financial year.
 * `amount` is the allocation as the CEO approved the budget; approved
 * adjustments change it during the year (App\Services\Budgeting::lineAmounts).
 * Changed only through App\Services\Budgeting.
 */
class BudgetLine extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = ['department_budget_id', 'department_id', 'financial_year', 'code', 'name', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
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
