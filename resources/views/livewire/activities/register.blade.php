@use('App\Support\Ui')
@php
    $sortIcon = fn ($col) => $sort === $col ? ($direction === 'asc' ? 'fa-arrow-up' : 'fa-arrow-down') : 'fa-sort text-slate-300';
    $sel = Ui::CONTROL.' appearance-none pr-8';
@endphp

<x-ui.page icon="fa-table-list" title="Activity register" subtitle="Every activity recorded, with its status, dates, team and cost." scroll="inner">
    <x-slot:pills>
        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 text-[11px] font-semibold font-numeric">{{ number_format($total) }} total</span>
    </x-slot:pills>

    <x-slot:actions>
        @can('reports.export')
            <div class="flex items-center gap-1" role="group" aria-label="Export">
                <a href="{{ route('reports.export', ['report' => 'activity-register', 'format' => 'xlsx'] + $exportQuery) }}" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-file-excel text-xs text-emerald-600"></i>Excel</a>
                <a href="{{ route('reports.export', ['report' => 'activity-register', 'format' => 'pdf'] + $exportQuery) }}" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-file-pdf text-xs text-red-600"></i>PDF</a>
            </div>
        @endcan
        @can('activities.manage')
            <a href="{{ route('activities.create') }}" wire:navigate class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-plus text-xs"></i>Record activity</a>
        @endcan
    </x-slot:actions>

    <x-slot:filters>
        <div class="relative flex items-center flex-1 min-w-[12rem]">
            <i class="fa-solid fa-magnifying-glass absolute left-3 text-gray-400 text-xs pointer-events-none"></i>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search title, reference, memo or officer…" aria-label="Search" class="{{ Ui::CONTROL }} pl-9">
        </div>
        <div class="relative shrink-0 sm:w-48">
            <select wire:model.live="status" class="{{ $sel }}" aria-label="Status">
                <option value="">All statuses</option>
                @foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
            </select>
        </div>
        <div class="relative shrink-0 sm:w-56">
            <select wire:model.live="department_id" class="{{ $sel }}" aria-label="Department">
                <option value="">All departments</option>
                @foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
            </select>
        </div>
        <div class="relative shrink-0 sm:w-36">
            <select wire:model.live="fy" class="{{ $sel }}" aria-label="Financial year">
                <option value="">All years</option>
                @foreach ($years as $y)<option value="{{ $y }}">FY {{ $y }}</option>@endforeach
            </select>
        </div>
        <button type="button" wire:click="$toggle('showMoreFilters')" class="{{ Ui::BTN_NEUTRAL }} shrink-0" aria-expanded="{{ $showMoreFilters ? 'true' : 'false' }}">
            <i class="fa-solid fa-sliders text-xs"></i>More filters
        </button>
        @if ($this->hasFilters())
            <button type="button" wire:click="clearFilters" class="shrink-0 text-[13px] font-medium text-slate-500 border border-slate-200 bg-white px-3 py-2 rounded-lg hover:text-red-500 hover:border-red-300 transition">
                <i class="fa-solid fa-xmark text-xs mr-1"></i>Clear
            </button>
        @endif

        @if ($showMoreFilters)
            <div class="w-full grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <select wire:model.live="staff_id" class="{{ $sel }}" aria-label="Officer">
                    <option value="">Any officer</option>
                    @foreach ($officers as $o)<option value="{{ $o->id }}">{{ $o->name }}{{ $o->staff_number ? " ({$o->staff_number})" : '' }}</option>@endforeach
                </select>
                <select wire:model.live="activity_type_id" class="{{ $sel }}" aria-label="Activity type">
                    <option value="">Any activity type</option>
                    @foreach ($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                </select>
                <select wire:model.live="region_id" class="{{ $sel }}" aria-label="Region">
                    <option value="">Any region</option>
                    @foreach ($regions as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach
                </select>
                <select wire:model.live="county_id" class="{{ $sel }}" aria-label="County">
                    <option value="">Any county</option>
                    @foreach ($counties as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select>
                <select wire:model.live="quarter" class="{{ $sel }}" aria-label="Quarter" @disabled(! $fy)>
                    <option value="">{{ $fy ? 'Whole year' : 'Choose a year for quarters' }}</option>
                    @foreach ([1, 2, 3, 4] as $q)<option value="{{ $q }}">Q{{ $q }}</option>@endforeach
                </select>
                <input type="date" wire:model.live="from" class="{{ Ui::CONTROL }}" aria-label="Starting from">
                <input type="date" wire:model.live="to" class="{{ Ui::CONTROL }}" aria-label="Starting up to">
                <select wire:model.live="flag" class="{{ $sel }}" aria-label="Flag">
                    <option value="">Any flag</option>
                    @foreach ($flags as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
            </div>
        @endif
    </x-slot:filters>

    <div class="flex-1 min-h-0 flex flex-col {{ Ui::CARD }}">
        <div class="{{ Ui::CAPTION }}">
            <h2 class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">Activities</h2>
            <span class="text-[11px] text-slate-500 font-numeric">Showing {{ number_format($activities->total()) }} of {{ number_format($total) }}</span>
        </div>

        @if ($activities->isEmpty())
            <x-ui.empty icon="fa-table-list" title="No activities" :line="$this->hasFilters() ? 'No activity matches the filters you have applied.' : 'Use “Record activity” to capture the first one from a memo or work plan.'" />
        @else
            <div class="hidden lg:block flex-1 min-h-0 overflow-auto">
                <table class="w-full">
                    <thead class="sticky top-0 z-10 bg-white border-b border-slate-100">
                        <tr>
                            @foreach (['reference' => 'Reference', 'title' => 'Activity', 'start_date' => 'Dates', 'days' => 'Days', 'staff' => 'Officers', 'cost' => 'Cost', 'status' => 'Status'] as $col => $label)
                                <th class="{{ Ui::TH }} {{ in_array($col, ['days', 'staff', 'cost']) ? 'text-right' : '' }}">
                                    <button type="button" wire:click="sortBy('{{ $col }}')" class="inline-flex items-center gap-1.5 hover:text-slate-600 uppercase">{{ $label }}<i class="fa-solid {{ $sortIcon($col) }} text-[9px]"></i></button>
                                </th>
                            @endforeach
                            <th class="{{ Ui::TH }}"><span class="sr-only">Flags</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($activities as $a)
                            <tr class="hover:bg-slate-50/60 transition-colors" wire:key="act-{{ $a->id }}">
                                <td class="{{ Ui::TD }} font-numeric whitespace-nowrap"><a href="{{ route('activities.show', $a) }}" wire:navigate class="font-semibold text-emerald-700 hover:underline">{{ $a->reference }}</a></td>
                                <td class="{{ Ui::TD }} max-w-md">
                                    <a href="{{ route('activities.show', $a) }}" wire:navigate class="font-medium text-slate-700 hover:text-emerald-700 line-clamp-1" title="{{ $a->title }}">{{ $a->title }}</a>
                                    <p class="text-[11px] text-slate-400 line-clamp-1">{{ $a->organisingDepartment?->name ?? 'No department' }}@if ($a->locationSummary()) · {{ $a->locationSummary() }}@endif</p>
                                </td>
                                <td class="{{ Ui::TD }} font-numeric whitespace-nowrap">{{ Ui::dateRange($a->start_date, $a->end_date) }}</td>
                                <td class="{{ Ui::TD }} text-right font-numeric">{{ $a->days }}</td>
                                <td class="{{ Ui::TD }} text-right font-numeric">{{ $a->staff_count }}@if ($a->external_count)<span class="text-slate-400"> +{{ $a->external_count }}</span>@endif</td>
                                <td class="{{ Ui::TD }} text-right"><x-ui.money :value="$a->cost_total" /></td>
                                <td class="{{ Ui::TD }}"><x-ui.status :status="$a->status" /></td>
                                <td class="{{ Ui::TD }} whitespace-nowrap">
                                    <div class="flex items-center gap-1.5">
                                        <x-ui.rag :rag="$a->rag()" />
                                        @if ($a->is_late_notice)<x-ui.badge classes="bg-amber-50 text-amber-700" icon="fa-clock">Late notice</x-ui.badge>@endif
                                        @if ($a->is_retrospective)<x-ui.badge classes="bg-red-50 text-red-700" icon="fa-backward">Retrospective</x-ui.badge>@endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="lg:hidden flex-1 min-h-0 overflow-y-auto divide-y divide-slate-100">
                @foreach ($activities as $a)
                    <a href="{{ route('activities.show', $a) }}" wire:navigate wire:key="card-{{ $a->id }}" class="block px-5 py-4 hover:bg-slate-50">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-[11px] font-numeric font-semibold text-emerald-700">{{ $a->reference }}</p>
                                <p class="text-sm font-medium text-slate-700">{{ $a->title }}</p>
                                <p class="text-[11px] text-slate-400 font-numeric">{{ Ui::dateRange($a->start_date, $a->end_date) }} · {{ $a->staff_count }} officers</p>
                            </div>
                            <x-ui.status :status="$a->status" />
                        </div>
                        <div class="mt-1.5 flex items-center justify-between"><x-ui.rag :rag="$a->rag()" /><x-ui.money :value="$a->cost_total" class="text-sm text-slate-700" /></div>
                    </a>
                @endforeach
            </div>

            @if ($activities->hasPages())
                <div class="shrink-0 px-5 py-3 border-t border-slate-100 bg-slate-50">{{ $activities->links('livewire.custom-pagination') }}</div>
            @endif
        @endif
    </div>
</x-ui.page>
