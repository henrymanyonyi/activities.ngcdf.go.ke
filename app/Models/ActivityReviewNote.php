<?php

namespace App\Models;

use App\Enums\ReviewRecommendation;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reviewer's comment or recommendation on a memo. Append-only: written by
 * App\Services\ActivityLifecycle and never edited or deleted, so the review
 * record cannot be rewritten.
 */
class ActivityReviewNote extends Model
{
    use Auditable;

    public const UPDATED_AT = null;

    protected $fillable = ['activity_id', 'user_id', 'recommendation', 'body'];

    protected function casts(): array
    {
        return [
            'recommendation' => ReviewRecommendation::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
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
