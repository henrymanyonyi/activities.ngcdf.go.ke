@use('App\Support\Ui')
@use('App\Support\Money')
@php $sel = Ui::CONTROL.' appearance-none pr-8'; @endphp

<x-ui.page icon="fa-users-viewfinder" title="Staff participation" :subtitle="$period->label.' · approved activities and later'" statsKey="partStats">
    <x-slot:actions>
        @can('reports.export')
            <a href="{{ route('reports.export', ['report' => 'officer-field-days', 'format' => 'xlsx', 'fy' => $fy, 'quarter' => $quarter, 'department_id' => $department_id]) }}" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-file-excel text-xs text-emerald-600"></i>Excel</a>
        @endcan
    </x-slot:actions>
    <x-slot:filters>
        <div class="relative flex items-center flex-1 min-w-[12rem]">
            <i class="fa-solid fa-magnifying-glass absolute left-3 text-gray-400 text-xs pointer-events-none"></i>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search officer or PF number…" class="{{ Ui::CONTROL }} pl-9" aria-label="Search">
        </div>
        <select wire:model.live="department_id" class="{{ $sel }} shrink-0 sm:w-56" aria-label="Department"><option value="">All departments</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
        <select wire:model.live="fy" class="{{ $sel }} shrink-0 sm:w-36" aria-label="Financial year"><option value="">FY {{ \App\Support\FinancialYear::current()->label() }}</option>@foreach ($years as $y)<option value="{{ $y }}">FY {{ $y }}</option>@endforeach</select>
        <select wire:model.live="quarter" class="{{ $sel }} shrink-0 sm:w-32" aria-label="Quarter"><option value="">Whole year</option>@foreach ([1, 2, 3, 4] as $q)<option value="{{ $q }}">Q{{ $q }}</option>@endforeach</select>
    </x-slot:filters>
    <x-slot:stats>
        <x-ui.stat :index="0" icon="fa-users" label="Staff involved" :value="$totals['staff']" :sub="$noneCount.' active staff with no participation'" />
        <x-ui.stat :index="1" icon="fa-clipboard-list" label="Activities" :value="$totals['activities']" tone="blue" />
        <x-ui.stat :index="2" icon="fa-calendar-days" label="Field days" :value="number_format($totals['days'])" tone="purple" sub="planned" />
        <x-ui.stat :index="3" icon="fa-coins" label="DSA · travel" :value="Money::format($totals['dsa'] + $totals['travel'])" money tone="amber" :sub="'DSA '.Money::format($totals['dsa']).' · travel '.Money::format($totals['travel'])" />
    </x-slot:stats>

    <x-ui.card title="Officers" :count="$rows->count()">
        @forelse ($rows as $r)
            @php $st = $r['staff']; $open = $expanded === $st->id; @endphp
            <div class="border-b border-slate-100 last:border-0" wire:key="s-{{ $st->id }}">
                <button type="button" wire:click="toggle({{ $st->id }})" aria-expanded="{{ $open ? 'true' : 'false' }}" class="w-full text-left px-5 py-3 flex flex-col md:flex-row md:items-center gap-3 hover:bg-slate-50/60">
                    <span class="min-w-0 flex-1">
                        <span class="block text-[13px] font-semibold text-slate-700">{{ $st->name }} <span class="font-normal text-slate-400 font-numeric">{{ $st->staff_number }}</span></span>
                        <span class="block text-[11px] text-slate-400">{{ $st->department?->name }} · last activity {{ Ui::date($r['last']) }}</span>
                    </span>
                    <span class="grid grid-cols-3 md:grid-cols-6 gap-3 text-right shrink-0">
                        <span><span class="block {{ Ui::MICRO }}">Activities</span><span class="text-[13px] font-bold font-numeric">{{ $r['count'] }}</span></span>
                        <span><span class="block {{ Ui::MICRO }}">Days plan · actual</span><span class="text-[13px] font-bold font-numeric">{{ $r['planned_days'] }} · {{ $r['actual_days'] }}</span></span>
                        <span><span class="block {{ Ui::MICRO }}">Missed</span><span class="text-[13px] font-bold font-numeric {{ $r['missed'] ? 'text-red-600' : '' }}">{{ $r['missed'] }}</span></span>
                        <span><span class="block {{ Ui::MICRO }}">FY days / limit</span><span class="text-[13px] font-bold font-numeric {{ $r['year_days'] > $r['year_limit'] ? 'text-red-600' : '' }}">{{ $r['year_days'] }}/{{ $r['year_limit'] }}</span></span>
                        <span><span class="block {{ Ui::MICRO }}">DSA</span><span class="text-[13px] font-bold font-numeric">{{ Money::format($r['dsa']) }}</span></span>
                        <span><span class="block {{ Ui::MICRO }}">Travel</span><span class="text-[13px] font-bold font-numeric">{{ Money::format($r['travel']) }}</span></span>
                    </span>
                    <i class="fa-solid fa-chevron-right text-[10px] text-slate-400 transition-transform {{ $open ? 'rotate-90' : '' }} hidden md:block"></i>
                </button>
                @if ($open)
                    <div class="px-5 pb-4 bg-slate-50/40">
                        <p class="text-[11px] text-slate-500 mb-2">By type: {{ $r['by_type']->map(fn ($n, $t) => "{$t} ({$n})")->implode(', ') }} · quarter days {{ $r['quarter_days'] }}/{{ $r['quarter_limit'] }} · cost incl. shared KES {{ Money::format($r['total']) }}</p>
                        @foreach ($r['activities']->sortByDesc(fn ($x) => $x['activity']->start_date) as $x)
                            <a href="{{ route('activities.show', $x['activity']) }}" wire:navigate class="flex items-center justify-between gap-3 px-3 py-2 bg-white border border-slate-100 rounded-lg mb-1.5 hover:border-emerald-300">
                                <span class="min-w-0"><span class="block text-[13px] text-slate-700 truncate">{{ $x['activity']->title }}</span><span class="block text-[11px] text-slate-400 font-numeric">{{ Ui::dateRange($x['activity']->start_date, $x['activity']->end_date) }} · {{ $x['participant']->role->label() }}</span></span>
                                <span class="flex items-center gap-2 shrink-0"><x-ui.badge :classes="$x['participant']->status->badgeClasses()">{{ $x['participant']->status->label() }}</x-ui.badge><x-ui.money :value="$x['cost']" class="text-[13px]" /></span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <x-ui.empty icon="fa-users" title="No participation in this period" :line="$search || $department_id ? 'No officer matches the filters.' : 'Participation appears once activities are approved.'" />
        @endforelse
    </x-ui.card>

    <x-ui.card title="Active staff with no participation in this period" icon="fa-user-slash" :count="$noneCount">
        <x-slot:actions><button type="button" wire:click="$toggle('showNone')" class="{{ Ui::BTN_TINT }}">{{ $showNone ? 'Hide' : 'Show list' }}</button></x-slot:actions>
        @if ($none)
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 px-5 py-3">
                @forelse ($none as $st)
                    <p class="text-[13px] text-slate-600 py-1 border-b border-slate-50">{{ $st->name }} <span class="text-[11px] text-slate-400">{{ $st->department?->name }}</span></p>
                @empty
                    <p class="text-sm text-slate-400">Everyone on the staff list took part.</p>
                @endforelse
            </div>
        @endif
    </x-ui.card>
</x-ui.page>
