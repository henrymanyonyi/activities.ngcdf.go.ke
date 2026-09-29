<?php

namespace App\Services;

use App\Models\AccessLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/** FRD CF-06: every sign-in, view of an activity, export and print. Append-only. */
class AccessLogger
{
    public const SIGN_IN = 'sign_in';

    public const SIGN_OUT = 'sign_out';

    public const FAILED_SIGN_IN = 'failed_sign_in';

    public const LOCKED_OUT = 'locked_out';

    public const VIEW = 'view';

    public const EXPORT = 'export';

    public const PRINT = 'print';

    public const DOWNLOAD = 'download';

    /** @param  array<string, mixed>  $meta */
    public function log(string $event, ?Model $subject = null, array $meta = [], ?int $userId = null, ?string $email = null): void
    {
        AccessLog::create([
            'user_id' => $userId ?? Auth::id(),
            'email' => $email ?? Auth::user()?->email,
            'event' => $event,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'meta' => $meta === [] ? null : $meta,
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}
