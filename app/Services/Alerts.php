<?php

namespace App\Services;

use App\Enums\ReviewOffice;
use App\Models\Activity;
use App\Models\User;
use App\Notifications\ActivityAlert;
use Illuminate\Support\Facades\Notification;

/**
 * Routes in-app alerts to the authorised users only (BR-11): the Office of
 * the CEO, and a Head of Department only about their own department's memos.
 */
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

    /** The office that holds a memo under review. */
    public function toOffice(ReviewOffice $office, ActivityAlert $alert): void
    {
        $this->send([$office->role()], $alert);
    }

    /** The active Head of Department of the memo's department, and the responsible Head of Department if different. */
    public function toDepartment(Activity $activity, ActivityAlert $alert): void
    {
        $recipients = User::query()->role(User::ROLE_HOD)->where('is_active', true)
            ->where(fn ($q) => $q
                ->when($activity->organising_department_id, fn ($q, $id) => $q->where('department_id', $id))
                ->when($activity->responsible_hod_id, fn ($q, $id) => $q->orWhereKey($id)))
            ->when(! $activity->organising_department_id && ! $activity->responsible_hod_id, fn ($q) => $q->whereRaw('1 = 0'))
            ->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, $alert);
        }
    }

    /**
     * Named users of the Office of the CEO (budget preparers). Anyone else is skipped.
     *
     * @param  iterable<User>  $users
     */
    public function toUsers(iterable $users, ActivityAlert $alert): void
    {
        $recipients = collect($users)->filter(fn (User $u) => $u->is_active && $u->hasAnyRole([User::ROLE_CEO, User::ROLE_CHIEF_OF_STAFF, User::ROLE_ASSISTANT_CHIEF_OF_STAFF]));

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, $alert);
        }
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
