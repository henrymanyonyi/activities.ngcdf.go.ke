{{--
    Where an activity memo is: status, who holds it, what happens next, who
    prepared it for whom, and the full review and decision history (oldest
    entries are never edited). Shared by the Head of Department's memo page
    and the Office of the CEO's activity page; actions go in the slot.
--}}
@props(['activity'])
@use('App\Support\Ui')
@use('App\Enums\ActivityStatus')
@php
    $a = $activity;
    $s = $a->status;
    $timeline = $a->statusHistory->map(fn ($h) => [
        'at' => $h->created_at, 'key' => 'h'.$h->id, 'by' => $h->actorLabel(),
        'title' => $h->from_status === null ? 'Created as a draft' : ($h->from_status === $h->to_status ? ($h->reason ?: $h->to_status->label()) : $h->to_status->label()),
        // A decision's reason repeats its label ("Approved: ..."): show only the comment.
        'body' => $h->from_status === $h->to_status ? null : (filled($h->reason) ? (Str::startsWith($h->reason, $h->to_status->label().': ') ? Str::after($h->reason, ': ') : ($h->reason === $h->to_status->label() ? null : $h->reason)) : null),
        'icon' => $h->to_status->icon(), 'classes' => $h->to_status->badgeClasses(), 'recommendation' => null,
    ])->concat($a->reviewNotes->map(fn ($n) => [
        'at' => $n->created_at, 'key' => 'n'.$n->id, 'by' => $n->user?->name ?? 'Unknown',
        'title' => 'Review comment', 'body' => $n->body,
        'icon' => 'fa-comment', 'classes' => 'bg-slate-100 text-slate-600', 'recommendation' => $n->recommendation,
    ]))->sortByDesc(fn ($e) => $e['at']->format('YmdHisu').$e['key'])->values();
    $correction = in_array($s, [ActivityStatus::ReturnedToHod, ActivityStatus::Returned]) ? $a->status_reason : null;
@endphp

<x-ui.card title="Memo workflow" icon="fa-route" {{ $attributes }}>
    <x-slot:actions>{{ $actions ?? '' }}</x-slot:actions>
    <div class="p-5 grid grid-cols-1 lg:grid-cols-5 gap-5">
        <div class="lg:col-span-2 space-y-3">
            <div class="rounded-lg border border-slate-200 p-3.5 space-y-2.5">
                <div class="flex items-center gap-2"><span class="{{ Ui::MICRO }} w-24 shrink-0">Status</span><x-ui.status :status="$s" /></div>
                <div class="flex items-start gap-2"><span class="{{ Ui::MICRO }} w-24 shrink-0 pt-0.5">With</span><span class="text-[13px] font-semibold text-slate-700">{{ $a->currentHolder() }}</span></div>
                <div class="flex items-start gap-2"><span class="{{ Ui::MICRO }} w-24 shrink-0 pt-0.5">Next step</span><span class="text-[13px] text-slate-600">{{ $a->nextAction() }}</span></div>
                <div class="flex items-start gap-2"><span class="{{ Ui::MICRO }} w-24 shrink-0 pt-0.5">Head of Dept.</span><span class="text-[13px] text-slate-600">{{ $a->responsibleHodLabel() ?? 'Not recorded' }}</span></div>
                <div class="flex items-start gap-2"><span class="{{ Ui::MICRO }} w-24 shrink-0 pt-0.5">Prepared by</span><span class="text-[13px] text-slate-600">{{ $a->creator?->name ?? ($a->submitted_by_name ?: 'Unknown') }}@if ($a->isPreparedOnBehalf())<span class="block text-[11px] text-purple-700"><i class="fa-solid fa-user-pen mr-1"></i>On behalf of the Head of Department</span>@endif</span></div>
                @if ($a->approved_at)
                    <div class="flex items-start gap-2"><span class="{{ Ui::MICRO }} w-24 shrink-0 pt-0.5">Decision</span><span class="text-[13px] text-emerald-700 font-semibold">Approved and authorised by {{ $a->approver?->name ?? 'the CEO' }}, {{ $a->approved_at->format('d M Y H:i') }}</span></div>
                @endif
            </div>
            @if ($correction)
                <div class="rounded-lg border border-orange-200 bg-orange-50 p-3.5 text-[13px] text-orange-800" role="note">
                    <p class="font-semibold mb-0.5"><i class="fa-solid fa-reply mr-1"></i>{{ $s === ActivityStatus::ReturnedToHod ? 'What to correct' : 'The CEO\'s comments' }}</p>
                    <p class="whitespace-pre-line">{{ $correction }}</p>
                </div>
            @elseif ($s === ActivityStatus::Declined && $a->status_reason)
                <div class="rounded-lg border border-red-200 bg-red-50 p-3.5 text-[13px] text-red-800" role="note">
                    <p class="font-semibold mb-0.5"><i class="fa-solid fa-circle-xmark mr-1"></i>Reason for declining</p>
                    <p class="whitespace-pre-line">{{ $a->status_reason }}</p>
                </div>
            @endif
        </div>

        <div class="lg:col-span-3">
            <h3 class="{{ Ui::MICRO }} mb-2">History, newest first</h3>
            <ol class="space-y-2.5 max-h-96 overflow-y-auto pr-1">
                @foreach ($timeline as $e)
                    <li class="flex gap-3" wire:key="tl-{{ $e['key'] }}">
                        <span class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0 {{ $e['classes'] }}"><i class="fa-solid {{ $e['icon'] }} text-[11px]"></i></span>
                        <div class="min-w-0">
                            <p class="text-[13px] text-slate-700"><span class="font-semibold">{{ $e['title'] }}</span>
                                @if ($e['recommendation'])<x-ui.badge :classes="$e['recommendation']->badgeClasses()" class="ml-1">{{ $e['recommendation']->label() }}</x-ui.badge>@endif
                            </p>
                            @if ($e['body'])<p class="text-[13px] text-slate-600 whitespace-pre-line">{{ $e['body'] }}</p>@endif
                            <p class="text-[11px] text-slate-400 font-numeric">{{ $e['by'] }} · {{ $e['at']->format('d M Y H:i') }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>
    </div>
</x-ui.card>
