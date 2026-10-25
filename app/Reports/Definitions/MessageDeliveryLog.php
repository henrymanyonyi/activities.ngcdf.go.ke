<?php

namespace App\Reports\Definitions;

use App\Models\OutboundMessage;
use App\Reports\Report;
use App\Services\OutboundAlerts;
use App\Support\Period;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Every action-needed email and SMS (App\Services\OutboundAlerts), with what it said and whether it was delivered. */
class MessageDeliveryLog extends Report
{
    public function key(): string
    {
        return 'message-log';
    }

    public function title(): string
    {
        return 'Email and SMS Delivery Log';
    }

    public function description(): string
    {
        return 'Action-needed emails and SMS: who they went to, what they said and whether they were delivered.';
    }

    public function icon(): string
    {
        return 'fa-paper-plane';
    }

    public function permission(): string
    {
        return 'audit.view';
    }

    public function columns(): array
    {
        return [
            'when' => ['When', 'text'],
            'who' => ['User', 'text'],
            'channel' => ['Channel', 'text'],
            'to' => ['Sent to', 'text'],
            'event' => ['Event', 'text'],
            'message' => ['Message', 'text'],
            'status' => ['Status', 'text'],
            'error' => ['Error', 'text'],
        ];
    }

    public function rows(array $filters): Collection
    {
        $period = Period::fromFilters($filters);

        return OutboundMessage::query()->whereBetween('created_at', [$period->from, $period->to])->with('user')->latest('id')->limit(5000)->get()
            ->map(fn (OutboundMessage $m) => [
                'when' => $m->created_at->format('d M Y H:i:s'),
                'who' => $m->user?->name,
                'channel' => $m->channel === 'sms' ? 'SMS' : 'Email',
                'to' => OutboundAlerts::displayRecipient($m),
                'event' => Str::headline($m->kind),
                'message' => $m->subject ?? Str::limit($m->body, 200),
                'status' => Str::headline($m->status).($m->sent_at ? ' '.$m->sent_at->format('H:i:s') : ''),
                'error' => $m->error ? Str::limit($m->error, 200) : null,
            ]);
    }
}
