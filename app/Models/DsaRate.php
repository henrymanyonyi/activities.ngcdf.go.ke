<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * FRD MD-05: DSA rate by job grade and destination category, with effective
 * dates. Past rates are kept so an activity is costed at the rate in force on
 * its start date (BR-02).
 */
class DsaRate extends Model
{
    use Auditable;

    protected $fillable = ['job_grade', 'destination_category', 'amount', 'effective_from', 'effective_to', 'recorded_by'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /** @param  Builder<static>  $query */
    public function scopeInForceOn(Builder $query, Carbon $date): void
    {
        $query->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }

    public static function lookup(?string $jobGrade, ?string $destinationCategory, Carbon $on): ?self
    {
        if (blank($jobGrade) || blank($destinationCategory)) {
            return null;
        }

        return static::query()
            ->whereRaw('UPPER(job_grade) = ?', [strtoupper(trim($jobGrade))])
            ->whereRaw('LOWER(destination_category) = ?', [strtolower(trim($destinationCategory))])
            ->inForceOn($on)
            ->orderByDesc('effective_from')
            ->first();
    }
}
