<?php

namespace App\Models;

use App\Enums\ActivityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['activity_id', 'from_status', 'to_status', 'reason', 'user_id', 'actor_name'];

    protected function casts(): array
    {
        return [
            'from_status' => ActivityStatus::class,
            'to_status' => ActivityStatus::class,
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

    public function actorLabel(): string
    {
        return $this->user?->name ?? $this->actor_name ?? 'System';
    }
}
