<?php

namespace App\Services\Sms;

use App\Contracts\SmsGateway;
use Illuminate\Support\Facades\Log;

/** Writes SMS to the log instead of sending them. Tests only (ACTIVITIES_SMS_DRIVER=log is ignored elsewhere). */
class LogSmsGateway implements SmsGateway
{
    public function send(string $mobile, string $message): ?string
    {
        Log::info('SMS', ['to' => $mobile, 'message' => $message]);

        return null;
    }
}
