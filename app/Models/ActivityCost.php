<?php

namespace App\Models;

use App\Enums\TravelMode;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cost line. Carries both the estimate and the actual so planned vs actual
 * is a column comparison. A null participant means a shared, activity-level cost.
 */
class ActivityCost extends Model
{
    use Auditable;

    protected $fillable = [
        'activity_id',
        'cost_category_id',
        'activity_participant_id',
        'description',
        'quantity',
        'unit_rate',
        'estimated_amount',
        'actual_amount',
        'is_computed',
        'dsa_rate_id',
        'computed_amount',
        'override_reason',
        'travel_mode',
        'finance_reference',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_rate' => 'decimal:2',
            'estimated_amount' => 'decimal:2',
            'actual_amount' => 'decimal:2',
            'computed_amount' => 'decimal:2',
            'is_computed' => 'boolean',
            'travel_mode' => TravelMode::class,
        ];
    }

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /** @return BelongsTo<CostCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CostCategory::class, 'cost_category_id');
    }

    /** @return BelongsTo<DsaRate, $this> */
    public function dsaRate(): BelongsTo
    {
        return $this->belongsTo(DsaRate::class);
    }

    public function isOverridden(): bool
    {
        return $this->is_computed && $this->computed_amount !== null && bccomp((string) $this->computed_amount, (string) $this->estimated_amount, 2) !== 0;
    }

    /** @return BelongsTo<ActivityParticipant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(ActivityParticipant::class, 'activity_participant_id');
    }

    public function isShared(): bool
    {
        return $this->activity_participant_id === null;
    }

    /** Actual where entered, estimate otherwise. */
    public function bestKnownAmount(): float
    {
        return (float) ($this->actual_amount ?? $this->estimated_amount);
    }
}
