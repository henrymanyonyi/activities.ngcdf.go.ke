<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\ActivityAlert;
use Illuminate\Support\Facades\Notification;

/** Routes in-app alerts to the three authorised users only (BR-11). */
class Alerts
{
    public function toCeo(ActivityAlert $alert): void
    {
        $this->send([User::ROLE_CEO], $alert);
    }

    /** Chief of Staff and Assistant Chief of Staff. */
    public function toStaffOfCeo(ActivityAlert $alert): void
    {
        $this->send([User::ROLE_CHIEF_OF_STAFF, User::ROLE_ASSISTANT_CHIEF_OF_STAFF], $alert);
    }

    /** @param  list<string>  $roles */
    private function send(array $roles, ActivityAlert $alert): void
    {
        $recipients = User::query()->role($roles)->where('is_active', true)->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, $alert);
        }
    }
}
