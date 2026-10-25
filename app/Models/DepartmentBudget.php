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
 * A department's workplan and budget for one financial year: its objectives,
 * its lines (the workplan's activities or votes, with their estimated costs)
 * and an optional uploaded workplan document, prepared as a draft (by the
 * Office of the CEO or the department's own Head) and counted only once the
 * CEO approves it; changed during the year only by approved adjustments.
 * Every submission, comment, change of amount, return and approval is kept in
 * `reviews`. One per department and year (a
 * discarded draft is soft-deleted). Status changes only through
 * App\Services\Budgeting.
 */
class DepartmentBudget extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = ['department_id', 'financial_year', 'objectives', 'notes', 'prepared_by'];

    protected $hidden = ['workplan_file_path'];

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

    /** @return HasMany<BudgetReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(BudgetReview::class)->latest('id');
    }

    public function hasWorkplanFile(): bool
    {
        return $this->workplan_file_path !== null;
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
