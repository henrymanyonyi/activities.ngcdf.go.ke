<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FRD PT-07 / EX-04 / BR-10: a change made after the activity was submitted or approved. */
class ActivityAmendment extends Model
{
    public const KIND_DATES = 'dates';

    public const KIND_EXTENSION = 'extension';

    public const KIND_PARTICIPANTS = 'participants';

    public const KIND_COST = 'cost';

    protected $fillable = ['activity_id', 'kind', 'summary', 'reason', 'before', 'after', 'returned_for_decision', 'user_id'];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'returned_for_decision' => 'boolean',
        ];
    }

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
