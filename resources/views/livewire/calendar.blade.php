@use('App\Support\Ui')
@php
    $sel = Ui::CONTROL.' appearance-none pr-8';
    $title = $view === 'week' ? 'Week of '.$days->first()->format('d M Y') : $anchor->format('F Y');
@endphp

<x-ui.page icon="fa-calendar-days" title="Calendar" :subtitle="$title">
    <x-slot:actions>
        <div class="flex items-center gap-1 bg-gray-50 border border-gray-200 rounded-lg p-1 w-fit" role="tablist">
            @foreach (['month' => ['fa-calendar', 'Month'], 'week' => ['fa-calendar-week', 'Week']] as $key => [$icon, $label])
                <button type="button" wire:click="$set('view', '{{ $key }}')" role="tab" aria-selected="{{ $view === $key ? 'true' : 'false' }}" class="inline-flex items-center gap-2 px-4 py-1.5 text-xs font-semibold rounded-md transition {{ $view === $key ? 'bg-emerald-600 text-white' : 'text-slate-500 hover:text-slate-800' }}"><i class="fa-solid {{ $icon }} text-[10px]"></i>{{ $label }}</button>
            @endforeach
        </div>
        <button type="button" wire:click="move(-1)" class="{{ Ui::BTN_NEUTRAL }}" aria-label="Previous"><i class="fa-solid fa-chevron-left text-xs"></i></button>
        <button type="button" wire:click="today" class="{{ Ui::BTN_NEUTRAL }}">Today</button>
        <button type="button" wire:click="move(1)" class="{{ Ui::BTN_NEUTRAL }}" aria-label="Next"><i class="fa-solid fa-chevron-right text-xs"></i></button>
    </x-slot:actions>

    <x-slot:filters>
        <select wire:model.live="department_id" class="{{ $sel }} flex-1 min-w-[12rem]" aria-label="Department"><option value="">All departments</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
        <select wire:model.live="region_id" class="{{ $sel }} shrink-0 sm:w-52" aria-label="Region"><option value="">All regions</option>@foreach ($regions as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach</select>
        <select wire:model.live="activity_type_id" class="{{ $sel }} shrink-0 sm:w-52" aria-label="Type"><option value="">All types</option>@foreach ($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select>
        <select wire:model.live="status" class="{{ $sel }} shrink-0 sm:w-48" aria-label="Status"><option value="">All open statuses</option>@foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach</select>
    </x-slot:filters>

    @if (count($joint))
        <div class="px-4 py-3 rounded-lg text-sm bg-blue-50 border border-blue-200 text-blue-800"><i class="fa-solid fa-people-arrows mr-2"></i>{{ intdiv(array_sum(array_map('count', $joint)), 2) }} pair(s) of activities by different departments are in the same county on overlapping dates. They are marked <i class="fa-solid fa-people-arrows text-[10px]"></i> below and may suit a joint mission.</div>
    @endif

    <div class="{{ Ui::CARD }}">
        <div class="grid grid-cols-7 border-b border-slate-200 bg-slate-50/60">
            @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d)<div class="px-2 py-2 {{ Ui::MICRO }} text-center">{{ $d }}</div>@endforeach
        </div>
        <div class="grid grid-cols-7">
            @foreach ($days as $day)
                @php
                    $items = $activities->filter(fn ($a) => $a->start_date->lte($day) && $a->end_date->gte($day));
                    $muted = $view === 'month' && $day->month !== $anchor->month;
                @endphp
                <div class="border-b border-r border-slate-100 p-1.5 {{ $view === 'week' ? 'min-h-[22rem]' : 'min-h-[7.5rem]' }} {{ $muted ? 'bg-slate-50/60' : '' }} {{ $day->isWeekend() ? 'bg-slate-50/30' : '' }}" wire:key="d-{{ $day->toDateString() }}">
                    <p class="text-[11px] font-numeric mb-1 {{ $day->isToday() ? 'inline-flex w-6 h-6 items-center justify-center rounded-full bg-emerald-600 text-white font-bold' : ($muted ? 'text-slate-300' : 'text-slate-500') }}">{{ $day->day }}</p>
                    <div class="space-y-1">
                        @foreach ($items->take($view === 'week' ? 20 : 4) as $a)
                            <a href="{{ route('activities.show', $a) }}" wire:navigate title="{{ $a->title }} · {{ $a->organisingDepartment?->name }} · {{ $a->status->label() }}"
                                class="block px-1.5 py-0.5 rounded text-[11px] leading-tight truncate {{ $a->status->badgeClasses() }} hover:ring-1 hover:ring-emerald-400">
                                @if (isset($joint[$a->id]))<i class="fa-solid fa-people-arrows text-[9px] mr-0.5"></i>@endif{{ $a->title }}
                            </a>
                        @endforeach
                        @if ($view === 'month' && $items->count() > 4)
                            <button type="button" wire:click="showWeek('{{ $day->toDateString() }}')" class="text-[10px] font-semibold text-emerald-700 hover:underline">+{{ $items->count() - 4 }} more</button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-ui.page>
