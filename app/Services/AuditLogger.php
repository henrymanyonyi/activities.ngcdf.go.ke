<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * The single writer of audit_logs. Model changes arrive through the Auditable
 * trait; business events (link opened, activity approved) call record() directly.
 */
class AuditLogger
{
    /** Never written to the log, even when changed. */
    private const REDACTED = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'token_encrypted', 'token_hash'];

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function record(Model|string $subject, string $event, ?array $old = null, ?array $new = null, ?string $actorName = null): AuditLog
    {
        return AuditLog::create([
            'auditable_type' => is_string($subject) ? $subject : $subject->getMorphClass(),
            'auditable_id' => is_string($subject) ? null : $subject->getKey(),
            'event' => $event,
            'old_values' => $old === null ? null : $this->clean($old),
            'new_values' => $new === null ? null : $this->clean($new),
            'user_id' => Auth::id(),
            'actor_name' => $actorName,
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function clean(array $values): array
    {
        return collect($values)
            ->except(self::REDACTED)
            ->except(['created_at', 'updated_at'])
            ->all();
    }
}
