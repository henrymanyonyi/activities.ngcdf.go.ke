<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One action-needed email or SMS and whether it was delivered. Written only by
 * App\Services\OutboundAlerts and App\Jobs\DeliverOutboundMessage; never edited or deleted.
 */
class OutboundMessage extends Model
{
    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    protected $fillable = ['user_id', 'activity_id', 'kind', 'channel', 'recipient', 'subject', 'body', 'url', 'from_office', 'status', 'provider_reference', 'error', 'sent_at'];

    protected function casts(): array
    {
        return [
            'from_office' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
