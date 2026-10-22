@use('App\Support\Ui')
@use('App\Support\Money')
@use('App\Enums\ActivityStatus')
@use('App\Services\MemoTracker')
@php
    $sel = Ui::CONTROL.' appearance-none pr-8';
    $isDepartment = $perspective === MemoTracker::PERSPECTIVE_DEPARTMENT;
    $tracker = app(MemoTracker::class);
    $subtitle = match ($perspective) {
        MemoTracker::PERSPECTIVE_CEO => 'Memos forwarded for your approval and authorisation, and your decisions.',
        MemoTracker::PERSPECTIVE_REVIEWER => 'Memos from Heads of Department to review, return for correction or forward to the CEO.',
        default => ($department?->name ?? 'Your department').': prepare memos, submit them for review and follow them to the CEO\'s decision.',
    };
@endphp

<x-ui.page icon="fa-envelope-open-text" title="Activity memos" :subtitle="$subtitle" scroll="inner" statsKey="memoStats">
    <x-slot:actions>
        @if ($isDepartment)
            <a href="{{ route('memos.create') }}" wire:navigate class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-plus text-xs"></i>Create new activity memo</a>
        @elseif (auth()->user()->can('activities.manage'))
            <a href="{{ route('activities.create') }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-user-pen text-xs"></i>Prepare a memo for a Head of Department</a>
        @endif
        @can('activities.view')
            <a href="{{ route('reports.show', 'memo-workflow') }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-file-lines text-xs"></i>Memo report</a>
        @endcan
    </x-slot:actions>

    <x-slot:tabs>
        <div role="tablist" class="flex flex-wrap gap-1.5">
            @foreach ($tabs as $key => $t)
                @php $on = $current === $key; @endphp
                <button type="button" role="tab" aria-selected="{{ $on ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')"
                    class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-[13px] font-semibold border transition {{ $on ? 'bg-emerald-600 border-emerald-600 text-white' : 'bg-white border-slate-200 text-slate-600 hover:border-emerald-300 hover:text-emerald-700' }}">
                    <i class="fa-solid {{ $t['icon'] }} text-[10px]"></i>{{ $t['label'] }}
                    <span class="font-numeric px-1.5 py-0.5 rounded-full text-[10px] font-bold {{ $on ? 'bg-white/20 text-white' : ($loop->first && $counts[$key] > 0 ? 'bg-amber-400 text-emerald-950' : 'bg-slate-100 text-slate-500') }}">{{ $counts[$key] }}</span>
                </button>
            @endforeach
        </div>
    </x-slot:tabs>

    <x-slot:filters>
        <div class="relative flex items-center flex-1 min-w-[12rem]">
            <i class="fa-solid fa-magnifying-glass absolute left-3 text-gray-400 text-xs pointer-events-none"></i>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search title or reference…" aria-label="Search" class="{{ Ui::CONTROL }} pl-9">
        </div>
        @unless ($isDepartment)
            <select wire:model.live="department_id" class="{{ $sel }} shrink-0 sm:w-56" aria-label="Department"><option value="">All departments</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
        @endunless
        <select wire:model.live="activity_type_id" class="{{ $sel }} shrink-0 sm:w-52" aria-label="Activity type"><option value="">Any activity type</option>@foreach ($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select>
        <input type="date" wire:model.live="from" class="{{ Ui::CONTROL }} shrink-0 sm:w-40" aria-label="Created from">
        <input type="date" wire:model.live="to" class="{{ Ui::CONTROL }} shrink-0 sm:w-40" aria-label="Created up to">
        @if ($this->hasFilters())
            <button type="button" wire:click="clearFilters" class="shrink-0 text-[13px] font-medium text-slate-500 border border-slate-200 bg-white px-3 py-2 rounded-lg hover:text-red-500 hover:border-red-300 transition"><i class="fa-solid fa-xmark text-xs mr-1"></i>Clear</button>
        @endif
    </x-slot:filters>

    @if ($budget)
        @php $kes = fn (?int $c) => $c === null ? 'Not set' : Money::format($c); @endphp
        <x-slot:stats>
            <x-ui.stat :index="0" icon="fa-wallet" :label="'Budget FY '.$budget['financial_year']" :value="$kes($budget['allocation'])" :money="$budget['allocation'] !== null" :sub="$budget['allocation'] === null ? 'No allocation recorded yet' : 'your department\'s allocation'" />
            <x-ui.stat :index="1" icon="fa-receipt" label="Spent" :value="$kes($budget['spent'])" money tone="amber" :sub="'from Finance returns'.($budget['spent_percent'] !== null ? ' · '.$budget['spent_percent'].'%' : '')" />
            <x-ui.stat :index="2" icon="fa-file-signature" label="Committed" :value="$kes($budget['committed'])" money tone="purple" :sub="$budget['approved_count'].' approved '.Str::plural('memo', $budget['approved_count']).' not yet spent'" />
            <x-ui.stat :index="3" icon="fa-scale-balanced" label="Available balance" :value="$kes($budget['balance'])" :money="$budget['balance'] !== null" :tone="($budget['balance'] ?? 0) < 0 ? 'red' : 'blue'" :sub="$budget['pending_count'] ? 'KES '.Money::format($budget['pending']).' more awaiting decision' : 'nothing awaiting decision'" />
        </x-slot:stats>
    @endif
    @if ($summary)
        <x-slot:stats>
            <x-ui.stat :index="0" icon="fa-envelope-open-text" label="Memos" :value="number_format($summary['originated'])" :sub="$summary['with_department'].' with departments'" />
            <x-ui.stat :index="1" icon="fa-magnifying-glass" label="In review" :value="number_format($summary['with_acos'] + $summary['with_cos'] + $summary['returned_by_ceo'])" :sub="'ACOS '.$summary['with_acos'].' · COS '.$summary['with_cos'].' · returned by CEO '.$summary['returned_by_ceo']" tone="blue" />
            <x-ui.stat :index="2" icon="fa-hourglass-half" label="Awaiting CEO" :value="number_format($summary['awaiting_ceo'])" :sub="$summary['pending_too_long'] ? $summary['pending_too_long'].' pending too long' : 'none pending too long'" :tone="$summary['pending_too_long'] ? 'red' : 'amber'" />
            <x-ui.stat :index="3" icon="fa-circle-check" label="Approved · declined" :value="number_format($summary['approved']).' · '.number_format($summary['declined'])" :sub="'KES '.Money::format($summary['approved_cents']).' approved; KES '.Money::format($summary['proposed_cents']).' proposed'" />
        </x-slot:stats>
    @endif

    <div class="flex-1 min-h-0 flex flex-col {{ Ui::CARD }}">
        <div class="{{ Ui::CAPTION }}">
            <h2 class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ $tabs[$current]['label'] }}</h2>
            <span class="text-[11px] text-slate-500 font-numeric">{{ number_format($memos->total()) }} {{ Str::plural('memo', $memos->total()) }}</span>
        </div>

        @if ($memos->isEmpty())
            <x-ui.empty icon="{{ $tabs[$current]['icon'] }}" title="Nothing here" :line="$this->hasFilters() ? 'No memo matches the filters you have applied.' : $tabs[$current]['empty']" />
        @else
            <div class="flex-1 min-h-0 overflow-y-auto divide-y divide-slate-100">
                @foreach ($memos as $a)
                    @php
                        $href = $isDepartment ? route('memos.show', $a) : route('activities.show', $a);
                        $comment = $a->latestComment();
                        $since = $movedAt[$a->id] ?? null;
                        $late = $since && in_array($a->status, [ActivityStatus::UnderReview, ActivityStatus::AwaitingDecision]) && $tracker->isTooLong($since);
                        $budget = $tracker->proposedCents($a);
                    @endphp
                    <a href="{{ $href }}" wire:navigate wire:key="memo-{{ $a->id }}" class="block px-5 py-4 hover:bg-slate-50/70 transition">
                        <div class="flex flex-col lg:flex-row lg:items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-[12px] font-numeric font-semibold text-emerald-700">{{ $a->reference }}</span>
                                    <x-ui.status :status="$a->status" />
                                    @if ($a->isPreparedOnBehalf())<x-ui.badge classes="bg-purple-50 text-purple-700" icon="fa-user-pen">Prepared on behalf</x-ui.badge>@endif
                                    @if ($late)<x-ui.badge classes="bg-red-50 text-red-700" icon="fa-clock">Pending since {{ Ui::date($since) }}</x-ui.badge>@endif
                                </div>
                                <p class="text-[15px] font-semibold text-slate-800 mt-1">{{ $a->title }}</p>
                                <p class="text-[12px] text-slate-500 mt-0.5">
                                    {{ $a->organisingDepartment?->name ?? 'No department' }}
                                    @if ($a->responsibleHodLabel()) · Head of Department: {{ $a->responsibleHodLabel() }}@endif
                                    @if ($a->isPreparedOnBehalf() && $a->creator) · prepared by {{ $a->creator->name }}@endif
                                    · {{ Ui::dateRange($a->start_date, $a->end_date) }}
                                </p>
                                @if ($comment)
                                    <p class="mt-2 text-[12px] text-slate-600 border-l-2 border-amber-300 pl-2.5 line-clamp-2"><span class="font-semibold">{{ $comment['by'] }}, {{ $comment['at']->format('d M Y') }}:</span> {{ $comment['body'] }}</p>
                                @endif
                            </div>
                            <div class="lg:w-80 shrink-0 grid grid-cols-2 lg:grid-cols-1 gap-1.5 text-[12px]">
                                <p><span class="{{ Ui::MICRO }} mr-1">With</span><span class="text-slate-700 font-medium">{{ $a->currentHolder() }}</span></p>
                                <p><span class="{{ Ui::MICRO }} mr-1">Next</span><span class="text-slate-600">{{ $a->nextAction() }}</span></p>
                                @if ($budget > 0)<p><span class="{{ Ui::MICRO }} mr-1">Budget</span><span class="text-slate-700 font-numeric">KES {{ Money::format($budget) }}</span></p>@endif
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>

            @if ($memos->hasPages())
                <div class="shrink-0 px-5 py-3 border-t border-slate-100 bg-slate-50">{{ $memos->links('livewire.custom-pagination') }}</div>
            @endif
        @endif
    </div>
</x-ui.page>
