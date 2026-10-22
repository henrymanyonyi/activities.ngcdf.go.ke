<?php

namespace App\Models;

use App\Enums\BudgetStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A department's budget for one financial year: its budget lines, prepared
 * as a draft and counted only once the CEO approves it; changed during the
 * year only by approved adjustments. One per department and year (a
 * discarded draft is soft-deleted). Status changes only through
 * App\Services\Budgeting.
 */
class DepartmentBudget extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = ['department_id', 'financial_year', 'notes', 'prepared_by'];

    protected function casts(): array
    {
        return [
            'status' => BudgetStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return HasMany<BudgetLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class)->orderBy('code')->orderBy('name');
    }

    /** @return HasMany<BudgetAdjustment, $this> */
    public function adjustments(): HasMany
    {
        return $this->hasMany(BudgetAdjustment::class)->latest('id');
    }

    /** @return BelongsTo<User, $this> */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /** @param  Builder<self>  $query */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', BudgetStatus::Approved->value);
    }

    public function title(): string
    {
        return ($this->department?->name ?? 'Department').' budget, FY '.$this->financial_year;
    }
}
