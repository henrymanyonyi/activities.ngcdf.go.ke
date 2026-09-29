<?php

namespace App\Listeners;

use App\Services\AccessLogger;
use Illuminate\Auth\Events\Logout;

class RecordSignOut
{
    public function __construct(private AccessLogger $log) {}

    public function handle(Logout $event): void
    {
        if ($event->user) {
            $this->log->log(AccessLogger::SIGN_OUT, userId: $event->user->getAuthIdentifier(), email: $event->user->email);
        }
    }
}
