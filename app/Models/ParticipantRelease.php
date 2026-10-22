<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An officer recalled, released or reassigned from an activity before its
 * end. The participant row keeps them on the activity with the shorter days;
 * this record keeps the original assignment, who asked and who recorded it,
 * and the planned-cost effect. Never edited or deleted.
 */
class ParticipantRelease extends Model
{
    use Auditable;

    public const KIND_RECALL = 'recall';

    public const KIND_RELEASE = 'release';

    public const KIND_REASSIGNMENT = 'reassignment';

    /** @var array<string, string> */
    public const KINDS = [
        self::KIND_RECALL => 'Recalled to the office',
        self::KIND_RELEASE => 'Released',
        self::KIND_REASSIGNMENT => 'Reassigned to another activity',
    ];

    /** How a cost line was treated. */
    public const LINE_REDUCED = 'reduced';

    public const LINE_PAYABLE = 'payable';

    public const LINE_REVISED = 'revised';

    public const LINE_ADDED = 'added';

    protected $fillable = [
        'activity_id', 'activity_participant_id', 'staff_id', 'kind', 'reason',
        'original_start_date', 'original_end_date', 'last_day', 'destination_activity_id',
        'initiated_by_name', 'recorded_by',
        'participant_cost_before', 'participant_cost_after', 'activity_total_before', 'activity_total_after', 'approved_total', 'lines',
    ];

    protected function casts(): array
    {
        return [
            'original_start_date' => 'date',
            'original_end_date' => 'date',
            'last_day' => 'date',
            'participant_cost_before' => 'decimal:2',
            'participant_cost_after' => 'decimal:2',
            'activity_total_before' => 'decimal:2',
            'activity_total_after' => 'decimal:2',
            'approved_total' => 'decimal:2',
            'lines' => 'array',
        ];
    }

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /** @return BelongsTo<ActivityParticipant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(ActivityParticipant::class, 'activity_participant_id');
    }

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }

    /** @return BelongsTo<Activity, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'destination_activity_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst($this->kind);
    }

    public function availableFrom(): Carbon
    {
        return $this->last_day->copy()->addDay();
    }
}
