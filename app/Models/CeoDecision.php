<?php

namespace App\Models;

use App\Enums\DecisionMode;
use App\Enums\DecisionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FRD 7.5. Append-only: a new decision is a new row. */
class CeoDecision extends Model
{
    protected $fillable = ['activity_id', 'decision', 'comment', 'mode', 'memo_reference', 'memo_date', 'document_id', 'recorded_by'];

    protected function casts(): array
    {
        return [
            'decision' => DecisionType::class,
            'mode' => DecisionMode::class,
            'memo_date' => 'date',
        ];
    }

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<ActivityDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(ActivityDocument::class);
    }
}
