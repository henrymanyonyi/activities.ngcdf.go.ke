<?php

namespace App\Reports\Definitions;

use App\Models\CeoDecision;
use App\Models\Directive;
use App\Reports\Report;
use App\Support\Period;
use Illuminate\Support\Collection;

class DecisionsRegister extends Report
{
    public function key(): string
    {
        return 'decisions-register';
    }

    public function title(): string
    {
        return 'Decisions Register';
    }

    public function description(): string
    {
        return 'CEO decisions and directives, with dates and status.';
    }

    public function icon(): string
    {
        return 'fa-gavel';
    }

    public function columns(): array
    {
        return [
            'date' => ['Date', 'date'],
            'kind' => ['Type', 'text'],
            'reference' => ['Activity', 'text'],
            'title' => ['Title', 'text'],
            'decision' => ['Decision / directive', 'text'],
            'how' => ['How recorded', 'text'],
            'memo' => ['Memo reference', 'text'],
            'status' => ['Status', 'text'],
            'by' => ['Recorded by', 'text'],
        ];
    }

    public function rows(array $filters): Collection
    {
        $period = Period::fromFilters($filters);

        $decisions = CeoDecision::query()
            ->whereBetween('created_at', [$period->from, $period->to])
            ->with(['activity', 'recorder'])
            ->get()
            ->map(fn (CeoDecision $d) => [
                'date' => $d->memo_date ?? $d->created_at,
                'kind' => 'Decision',
                'reference' => $d->activity?->reference,
                'title' => $d->activity?->title,
                'decision' => $d->decision->label().($d->comment ? ': '.$d->comment : ''),
                'how' => $d->mode->label(),
                'memo' => $d->memo_reference,
                'status' => $d->activity?->status->label(),
                'by' => $d->recorder?->name,
                '_activity_id' => $d->activity_id,
            ]);

        $directives = Directive::query()
            ->whereBetween('created_at', [$period->from, $period->to])
            ->with(['activity', 'issuer', 'department'])
            ->get()
            ->map(fn (Directive $d) => [
                'date' => $d->created_at,
                'kind' => 'Directive',
                'reference' => $d->activity?->reference,
                'title' => $d->activity?->title ?? '—',
                'decision' => $d->body.($d->department ? ' (to '.$d->department->name.')' : ''),
                'how' => $d->on_ceo_instruction ? 'On the CEO\'s instruction' : 'By the CEO',
                'memo' => null,
                'status' => ucfirst($d->status).($d->isOverdue() ? ' (overdue)' : ''),
                'by' => $d->issuer?->name,
                '_activity_id' => $d->activity_id,
            ]);

        return $decisions->concat($directives)->sortByDesc(fn ($r) => $r['date'])->values();
    }
}
