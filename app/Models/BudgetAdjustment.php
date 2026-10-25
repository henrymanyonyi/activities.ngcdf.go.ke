<?php

namespace App\Models;

use App\Enums\BudgetStatus;
use App\Models\Concerns\Auditable;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A change to an approved department budget during the year: a supplementary
 * allocation (more money, on an existing line or a new one), a reduction, or
 * a reallocation between two of its lines (the department total is
 * unchanged). Counts only once the CEO approves it. Status changes only
 * through App\Services\Budgeting.
 */
class BudgetAdjustment extends Model
{
    use Auditable, SoftDeletes;

    public const KIND_SUPPLEMENTARY = 'supplementary';

    public const KIND_REDUCTION = 'reduction';

    public const KIND_REALLOCATION = 'reallocation';

    /** @var array<string, string> */
    public const KINDS = [
        self::KIND_SUPPLEMENTARY => 'Supplementary allocation (increase)',
        self::KIND_REDUCTION => 'Reduction',
        self::KIND_REALLOCATION => 'Reallocation between lines',
    ];

    protected $fillable = ['department_budget_id', 'kind', 'budget_line_id', 'from_budget_line_id', 'new_line_code', 'new_line_name', 'amount', 'reason', 'effective_on', 'prepared_by'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'effective_on' => 'date',
            'line_before' => 'decimal:2',
            'line_after' => 'decimal:2',
            'from_line_before' => 'decimal:2',
            'from_line_after' => 'decimal:2',
            'status' => BudgetStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
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

    /** @return BelongsTo<BudgetLine, $this> */
    public function fromLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'from_budget_line_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
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

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    /** The change to the department's total allocation, in cents: reallocations move money, they add none. */
    public function netCents(): int
    {
        $amount = Money::toCents($this->amount);

        return match ($this->kind) {
            self::KIND_SUPPLEMENTARY => $amount,
            self::KIND_REDUCTION => -$amount,
            default => 0,
        };
    }

    /** What it does, in words: "KES 50,000.00 from 2210300 · Travel to 2211200 · Fuel". */
    public function summary(): string
    {
        $amount = 'KES '.Money::format(Money::toCents($this->amount));
        $to = $this->line?->label(withYear: false) ?? trim(($this->new_line_code ? $this->new_line_code.' · ' : '').$this->new_line_name.' (new line)');

        return match ($this->kind) {
            self::KIND_SUPPLEMENTARY => "{$amount} added to {$to}",
            self::KIND_REDUCTION => "{$amount} taken off {$to}",
            default => "{$amount} moved from {$this->fromLine?->label(withYear: false)} to {$to}",
        };
    }
}
