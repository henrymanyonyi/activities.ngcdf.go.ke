<?php

namespace App\Reports\Definitions;

use App\Models\AccessLog;
use App\Models\AuditLog;
use App\Reports\Report;
use App\Support\Period;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AccessAndAuditLog extends Report
{
    public function key(): string
    {
        return 'access-audit';
    }

    public function title(): string
    {
        return 'Access and Audit Log';
    }

    public function description(): string
    {
        return 'Sign-ins, views, exports, prints and changes.';
    }

    public function icon(): string
    {
        return 'fa-clock-rotate-left';
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
            'event' => ['Event', 'text'],
            'subject' => ['Record', 'text'],
            'detail' => ['Detail', 'text'],
            'ip' => ['IP address', 'text'],
        ];
    }

    public function rows(array $filters): Collection
    {
        $period = Period::fromFilters($filters);

        $access = AccessLog::query()->whereBetween('created_at', [$period->from, $period->to])->with('user')->latest('id')->limit(5000)->get()
            ->map(fn (AccessLog $l) => [
                'sort' => $l->created_at,
                'when' => $l->created_at->format('d M Y H:i:s'),
                'who' => $l->user?->name ?? $l->email,
                'event' => Str::headline($l->event),
                'subject' => $l->subject_type ? class_basename($l->subject_type).' #'.$l->subject_id : null,
                'detail' => $l->meta ? json_encode($l->meta) : null,
                'ip' => $l->ip_address,
            ]);

        $changes = AuditLog::query()->whereBetween('created_at', [$period->from, $period->to])->with('user')->latest('id')->limit(5000)->get()
            ->map(fn (AuditLog $l) => [
                'sort' => $l->created_at,
                'when' => $l->created_at->format('d M Y H:i:s'),
                'who' => $l->actorLabel(),
                'event' => Str::headline($l->event),
                'subject' => class_basename($l->auditable_type).($l->auditable_id ? ' #'.$l->auditable_id : ''),
                'detail' => $l->new_values ? Str::limit(json_encode($l->new_values), 300) : null,
                'ip' => $l->ip_address,
            ]);

        return $access->concat($changes)->sortByDesc('sort')->map(fn ($r) => collect($r)->except('sort')->all())->values();
    }
}
