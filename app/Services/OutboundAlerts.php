<?php

namespace App\Services;

use App\Jobs\DeliverOutboundMessage;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\ActivityAlert;
use App\Support\MobileNumber;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Action-needed email and SMS (exception to NT-03 / BR-11 approved 25 October 2026).
 * Only an alert marked with ActivityAlert::needsAction(), only to the CEO (since
 * 25 October 2026), Heads of Department, the Chief of Staff and the Assistant, and only the reference and
 * the step: the details stay in OCEO. Each message is logged, then delivered
 * once the surrounding transaction commits (as NG-CDF Careers does); a failed
 * delivery is marked failed on its log and never undoes or breaks the action.
 */
class OutboundAlerts
{
    /** The roles that get email and SMS. */
    public const ROLES = [User::ROLE_CEO, User::ROLE_HOD, User::ROLE_CHIEF_OF_STAFF, User::ROLE_ASSISTANT_CHIEF_OF_STAFF];

    public function __construct(private AppSettings $settings) {}

    /** @param  Collection<int, User>  $recipients */
    public function send(Collection $recipients, ActivityAlert $alert): void
    {
        if ($alert->outbound === null) {
            return;
        }

        $email = $this->settings->bool('alerts_email');
        $sms = $this->settings->bool('alerts_sms');
        if (! $email && ! $sms) {
            return;
        }

        foreach (EloquentCollection::make($recipients->values()->all())->loadMissing(['roles', 'staff']) as $user) {
            if (! $user->is_active || ! $user->hasAnyRole(self::ROLES)) {
                continue;
            }

            $url = ActivityAlert::destination($alert->toArray($user), $user);
            $channels = array_filter([
                'mail' => $email ? $user->email : null,
                'sms' => $sms ? $user->mobileNumber() : null,
            ]);

            foreach ($channels as $channel => $address) {
                $message = OutboundMessage::create([
                    'user_id' => $user->id,
                    'activity_id' => $alert->activityId,
                    'kind' => $alert->kind,
                    'channel' => $channel,
                    'recipient' => $address,
                    'subject' => $channel === 'mail' ? 'OCEO: '.$alert->outbound['subject'] : null,
                    'body' => $channel === 'mail' ? $this->emailBody($user, $alert) : $this->smsBody($alert, $url),
                    'url' => $url,
                    'from_office' => $this->fromOffice(),
                    'status' => OutboundMessage::QUEUED,
                ]);

                DB::afterCommit(fn () => $this->deliver($message->id));
            }
        }
    }

    /**
     * To an officer without an account, at the email and mobile number on the staff list: the delegated
     * memo request and what the Head of Department did with the memo. The same rules: reference only,
     * logged, and always sent (it is the only way they hear), so the Settings switches do not apply.
     * The link is their way in, so it goes in the message; nothing else about the memo does.
     *
     * @param  array{subject: string, line: string, todo: string}  $text
     */
    public function toContact(string $name, ?string $email, ?string $mobile, string $kind, array $text, ?string $url, ?int $activityId = null): void
    {
        $channels = array_filter([
            'mail' => filled($email) ? mb_strtolower(trim((string) $email)) : null,
            'sms' => MobileNumber::normalise($mobile),
        ]);

        foreach ($channels as $channel => $address) {
            $message = OutboundMessage::create([
                'user_id' => null,
                'activity_id' => $activityId,
                'kind' => $kind,
                'channel' => $channel,
                'recipient' => $address,
                'subject' => $channel === 'mail' ? 'OCEO: '.$text['subject'] : null,
                'body' => $channel === 'mail' ? "Dear {$name},\n\n{$text['line']}\n\n{$text['todo']}" : "NG-CDF OCEO: {$text['line']}".($url ? " Open: {$url}" : ''),
                'url' => $url,
                'from_office' => $this->fromOffice(),
                'status' => OutboundMessage::QUEUED,
            ]);

            DB::afterCommit(fn () => $this->deliver($message->id));
        }
    }

    /** One or two SMS parts: who it is from, what happened and where to act. */
    public function smsBody(ActivityAlert $alert, string $url): string
    {
        return "NG-CDF OCEO: {$alert->outbound['line']} Sign in to act: {$url}";
    }

    public function emailBody(User $user, ActivityAlert $alert): string
    {
        return "Dear {$user->name},\n\n{$alert->outbound['line']}\n\n{$alert->outbound['todo']}";
    }

    /**
     * The action behind the message was taken by the CEO, the Chief of Staff or the Assistant: its email is
     * headed, and sent, as from the Office of the CEO. Anyone else (a Head of Department, an officer through a
     * memo request, the System Administrator, the daily reminders) keeps the system's own heading.
     */
    private function fromOffice(): bool
    {
        return (bool) Auth::user()?->hasAnyRole([User::ROLE_CEO, User::ROLE_CHIEF_OF_STAFF, User::ROLE_ASSISTANT_CHIEF_OF_STAFF]);
    }

    /** For the delivery log: the mobile number masked. */
    public static function displayRecipient(OutboundMessage $message): string
    {
        return $message->channel === 'sms' ? MobileNumber::mask($message->recipient) : $message->recipient;
    }

    private function deliver(int $messageId): void
    {
        try {
            DeliverOutboundMessage::dispatchSync($messageId);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
