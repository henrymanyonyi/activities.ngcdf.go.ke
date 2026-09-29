<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** FRD MD-08: a department's allocation for a financial year. */
class BudgetLine extends Model
{
    use Auditable;

    protected $fillable = ['department_id', 'financial_year', 'code', 'name', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
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

    public function label(): string
    {
        return trim(($this->code ? $this->code.' · ' : '').$this->name.' ('.$this->financial_year.')');
    }
}
