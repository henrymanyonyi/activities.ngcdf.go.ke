<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FRD RP-02: the back-to-office report. Recorded by the Office of the CEO
 * (accepted at once), or submitted by the Head of Department and accepted or
 * returned for correction by the Office of the CEO (21 October 2026).
 */
class ReportReceipt extends Model
{
    use Auditable;

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_ACCEPTED = 'accepted';

    protected $fillable = ['activity_id', 'received_on', 'outputs_achieved', 'findings', 'recommendations', 'document_id', 'recorded_by', 'submitted_by', 'submitted_at'];

    protected function casts(): array
    {
        return ['received_on' => 'date', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isReturned(): bool
    {
        return $this->status === self::STATUS_RETURNED;
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /** @return BelongsTo<ActivityDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(ActivityDocument::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
