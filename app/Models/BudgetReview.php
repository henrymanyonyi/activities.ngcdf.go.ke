<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One step in the review of a department's workplan and budget: a submission
 * or resubmission (with the lines as submitted), a comment, a change of amount
 * by the CEO before approval, a return for adjustment, or the approval (with
 * the lines as approved). Written only by App\Services\Budgeting; never edited
 * or deleted.
 */
class BudgetReview extends Model
{
    public const UPDATED_AT = null;

    public const SUBMITTED = 'submitted';

    public const RESUBMITTED = 'resubmitted';

    public const COMMENT = 'comment';

    public const AMOUNT_CHANGED = 'amount_changed';

    public const RETURNED = 'returned';

    public const APPROVED = 'approved';

    /** @var array<string, array{0: string, 1: string}> event => [label, icon] */
    public const EVENTS = [
        self::SUBMITTED => ['Submitted to the CEO', 'fa-paper-plane'],
        self::RESUBMITTED => ['Resubmitted to the CEO', 'fa-rotate-right'],
        self::COMMENT => ['Comment', 'fa-comment'],
        self::AMOUNT_CHANGED => ['Amount changed by the CEO', 'fa-pen-to-square'],
        self::RETURNED => ['Returned for adjustment', 'fa-reply'],
        self::APPROVED => ['Approved', 'fa-circle-check'],
    ];

    protected $fillable = ['department_budget_id', 'budget_line_id', 'event', 'comment', 'amount_before', 'amount_after', 'snapshot', 'user_id'];

    protected function casts(): array
    {
        return [
            'amount_before' => 'decimal:2',
            'amount_after' => 'decimal:2',
            'snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Budget review history is never edited.'));
        static::deleting(fn () => throw new LogicException('Budget review history is never deleted.'));
    }

    /** @return BelongsTo<DepartmentBudget, $this> */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(DepartmentBudget::class, 'department_budget_id');
    }

    /** @return BelongsTo<BudgetLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'budget_line_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return self::EVENTS[$this->event][0] ?? $this->event;
    }

    public function icon(): string
    {
        return self::EVENTS[$this->event][1] ?? 'fa-circle';
    }
}
