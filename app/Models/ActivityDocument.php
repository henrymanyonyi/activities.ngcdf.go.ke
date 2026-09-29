<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file attached to an activity. Stored encrypted on the private disk (CF-08)
 * by App\Services\DocumentStore and served only to signed-in users.
 */
class ActivityDocument extends Model
{
    use Auditable;

    public const KIND_MEMO = 'memo';

    public const KIND_PARTICIPANT_LIST = 'participant_list';

    public const KIND_DECISION = 'decision';

    public const KIND_REPORT = 'report';

    public const KIND_OTHER = 'other';

    protected $fillable = [
        'activity_id',
        'kind',
        'original_name',
        'disk',
        'path',
        'mime_type',
        'size',
        'uploaded_by',
        'submission_link_id',
    ];

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            self::KIND_MEMO => 'Memo',
            self::KIND_PARTICIPANT_LIST => 'Participant list',
            self::KIND_DECISION => 'CEO decision (scanned)',
            self::KIND_REPORT => 'Back-to-office report',
            default => 'Supporting document',
        };
    }
}
