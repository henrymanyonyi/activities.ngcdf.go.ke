<?php

namespace App\Jobs;

use App\Contracts\SmsGateway;
use App\Mail\AlertMessage;
use App\Models\OutboundMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Delivers one logged email or SMS and records the outcome. Run synchronously by App\Services\OutboundAlerts. */
class DeliverOutboundMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public int $messageId) {}

    public function handle(SmsGateway $sms): void
    {
        $message = OutboundMessage::query()->find($this->messageId);
        if (! $message || $message->status === OutboundMessage::SENT) {
            return;
        }

        try {
            $reference = null;
            if ($message->channel === 'sms') {
                $reference = $sms->send($message->recipient, $message->body);
            } else {
                Mail::to($message->recipient)->send(new AlertMessage((string) $message->subject, $message->body, (string) $message->url, $message->user_id !== null));
            }
        } catch (Throwable $e) {
            $this->failed($e);

            throw $e;
        } finally {
            $this->forgetCode($message);
        }

        $message->update(['status' => OutboundMessage::SENT, 'provider_reference' => $reference, 'sent_at' => now(), 'error' => null]);
    }

    /** A one-time code is never kept in the log, so nobody reading it can open the memo request. */
    private function forgetCode(OutboundMessage $message): void
    {
        if ($message->kind === 'memo_request_code') {
            $message->forceFill(['body' => preg_replace('/\b\d{6}\b/', '******', $message->body)])->save();
        }
    }

    public function failed(Throwable $e): void
    {
        OutboundMessage::query()->whereKey($this->messageId)->update(['status' => OutboundMessage::FAILED, 'error' => mb_substr($e->getMessage(), 0, 1000)]);
    }
}
