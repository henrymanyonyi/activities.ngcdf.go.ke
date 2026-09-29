@use('App\Support\Ui')
@use('App\Support\Money')
@php
    $sel = Ui::CONTROL.' appearance-none pr-8';
    $maxDept = max(1, (int) $topDepartments->max('total'));
    $statusTiles = [
        ['planned', 'Being planned', 'fa-pen-ruler', 'slate', ['status' => '']],
        ['approved', 'Approved', 'fa-circle-check', 'emerald', ['status' => 'approved']],
        ['in_progress', 'In progress', 'fa-person-walking-luggage', 'blue', ['status' => 'in_progress']],
        ['completed', 'Delivered', 'fa-flag-checkered', 'emerald', ['status' => '']],
        ['postponed', 'Postponed', 'fa-calendar-xmark', 'purple', ['status' => 'postponed']],
        ['cancelled', 'Cancelled or declined', 'fa-ban', 'red', ['status' => 'cancelled']],
    ];
@endphp

<x-ui.page icon="fa-gauge-high" title="Dashboard" :subtitle="$period->label.' · field activities across the Board'" statsKey="dashStats">
    <x-slot:actions>
        <div class="relative w-36">
            <select wire:model.live="fy" class="{{ $sel }}" aria-label="Financial year">
                <option value="">FY {{ \App\Support\FinancialYear::current()->label() }}</option>
                @foreach ($years as $y)<option value="{{ $y }}">FY {{ $y }}</option>@endforeach
            </select>
        </div>
        <div class="relative w-32">
            <select wire:model.live="quarter" class="{{ $sel }}" aria-label="Quarter">
                <option value="">Whole year</option>
                @foreach ([1, 2, 3, 4] as $q)<option value="{{ $q }}">Q{{ $q }}</option>@endforeach
            </select>
        </div>
    </x-slot:actions>

    <x-slot:stats>
        <x-ui.stat :index="0" icon="fa-gavel" label="Awaiting your decision" :value="$awaiting" tone="amber" :href="route('decisions.index')" sub="open the decision queue" />
        <x-ui.stat :index="1" icon="fa-person-walking-luggage" label="In the field today" :value="$inField" tone="blue" :href="route('field-today')" sub="officers on an activity" />
        <x-ui.stat :index="2" icon="fa-file-circle-exclamation" label="Overdue reports" :value="$overdueReports" :tone="$overdueReports ? 'red' : 'slate'" :href="route('activities.index', ['flag' => 'report_overdue'])" sub="back-to-office reports" />
        <x-ui.stat :index="3" icon="fa-bullseye" label="Delivery rate" :value="$deliveryRate === null ? '—' : $deliveryRate.'%'" :sub="$dueCount.' approved '.Str::plural('activity', $dueCount).' due to have ended'" />
        <x-ui.stat :index="4" icon="fa-coins" label="Planned cost" :value="Money::format($planned)" money tone="amber" :href="$link()" :sub="$period->label" />
        <x-ui.stat :index="5" icon="fa-receipt" label="Actual cost" :value="Money::format($actual)" money :sub="$planned ? 'variance KES '.Money::format($planned - $actual) : null" />
        <x-ui.stat :index="6" icon="fa-clock" label="Late notice" :value="$lateNotice" :tone="$lateNotice ? 'amber' : 'slate'" :href="$link(['flag' => 'late_notice'])" sub="recorded close to the start date" />
        <x-ui.stat :index="7" icon="fa-backward" label="Retrospective" :value="$retrospective" :tone="$retrospective ? 'red' : 'slate'" :href="$link(['flag' => 'retrospective'])" sub="took place before approval" />
    </x-slot:stats>

    <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-3">
        @foreach ($statusTiles as [$key, $label, $icon, $tone, $q])
            <a href="{{ $link($q) }}" wire:navigate class="{{ Ui::CARD }} px-4 py-3 hover:border-emerald-300 transition">
                <p class="{{ Ui::MICRO }} flex items-center gap-1.5"><i class="fa-solid {{ $icon }} text-[10px]"></i>{{ $label }}</p>
                <p class="text-2xl font-bold text-slate-800 font-numeric">{{ $counts[$key] }}</p>
            </a>
        @endforeach
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <x-ui.card title="Awaiting decision" icon="fa-gavel" :count="$awaiting" class="xl:col-span-2">
            <x-slot:actions><a href="{{ route('decisions.index') }}" wire:navigate class="{{ Ui::BTN_TINT }}">Decision queue</a></x-slot:actions>
            @forelse ($queue as $a)
                <a href="{{ route('activities.show', $a) }}" wire:navigate class="flex items-center justify-between gap-3 px-5 py-3 border-b border-slate-100 last:border-0 hover:bg-slate-50/60">
                    <span class="min-w-0">
                        <span class="block text-[13px] font-medium text-slate-700 truncate">{{ $a->title }}</span>
                        <span class="block text-[11px] text-slate-400 font-numeric">{{ $a->reference }} · {{ $a->organisingDepartment?->name }} · {{ Ui::dateRange($a->start_date, $a->end_date) }} · {{ $a->staff_count }} officers</span>
                    </span>
                    <span class="flex items-center gap-2 shrink-0">
                        @if ($a->is_late_notice)<x-ui.badge classes="bg-amber-50 text-amber-700" icon="fa-clock">Late</x-ui.badge>@endif
                        <x-ui.money :value="$a->estimated_total" class="text-[13px] text-slate-700" />
                    </span>
                </a>
            @empty
                <x-ui.empty icon="fa-check-double" title="Nothing awaiting your decision" class="!py-8" />
            @endforelse
        </x-ui.card>

        <x-ui.card title="Top departments by spend" icon="fa-building-columns">
            <x-slot:actions><a href="{{ route('department-costs', ['fy' => $filters['fy'], 'quarter' => $filters['quarter']]) }}" wire:navigate class="{{ Ui::BTN_TINT }}">Department costs</a></x-slot:actions>
            <div class="p-5 space-y-3">
                @forelse ($topDepartments as $d)
                    <div>
                        <div class="flex items-center justify-between text-[13px] mb-1"><span class="text-slate-600 truncate">{{ $d['department']?->name ?? 'Unassigned' }}</span><x-ui.money :value="$d['total']" class="text-slate-700" /></div>
                        <div class="h-2 bg-slate-100 rounded-full overflow-hidden"><div class="h-full bg-emerald-500 rounded-full" style="width: {{ max(2, intdiv($d['total'] * 100, $maxDept)) }}%"></div></div>
                        @if ($d['budget'] !== null)<p class="text-[11px] {{ $d['variance'] < 0 ? 'text-red-600' : 'text-slate-400' }} font-numeric mt-0.5">Budget {{ Money::format($d['budget']) }} · {{ $d['variance'] < 0 ? 'over by '.Money::format(-$d['variance']) : Money::format($d['variance']).' remaining' }}</p>@endif
                    </div>
                @empty
                    <p class="text-sm text-slate-400 text-center py-6">No spend recorded in this period.</p>
                @endforelse
            </div>
        </x-ui.card>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <x-ui.card title="Commencement to confirm" icon="fa-person-walking-luggage" :count="$commencementDue->count()">
            @forelse ($commencementDue as $a)
                <a href="{{ route('activities.show', $a) }}" wire:navigate class="flex items-center justify-between gap-3 px-5 py-3 border-b border-slate-100 last:border-0 hover:bg-slate-50/60">
                    <span class="min-w-0"><span class="block text-[13px] font-medium text-slate-700 truncate">{{ $a->title }}</span><span class="block text-[11px] text-slate-400 font-numeric">Started {{ Ui::date($a->start_date) }}</span></span>
                    <x-ui.rag :rag="$a->commencementRag()" />
                </a>
            @empty
                <x-ui.empty icon="fa-check" title="All started activities are confirmed" class="!py-8" />
            @endforelse
        </x-ui.card>

        <x-ui.card title="Starting in the next 14 days" icon="fa-calendar-week" :count="$upcoming->count()">
            <x-slot:actions><a href="{{ route('calendar') }}" wire:navigate class="{{ Ui::BTN_TINT }}">Calendar</a></x-slot:actions>
            @forelse ($upcoming as $a)
                <a href="{{ route('activities.show', $a) }}" wire:navigate class="block px-5 py-3 border-b border-slate-100 last:border-0 hover:bg-slate-50/60">
                    <span class="block text-[13px] font-medium text-slate-700 truncate">{{ $a->title }}</span>
                    <span class="block text-[11px] text-slate-400 font-numeric">{{ Ui::dateRange($a->start_date, $a->end_date) }} · {{ $a->organisingDepartment?->name }}</span>
                </a>
            @empty
                <x-ui.empty icon="fa-calendar" title="Nothing approved to start soon" class="!py-8" />
            @endforelse
        </x-ui.card>

        <x-ui.card title="Overdue directives" icon="fa-list-check" :count="$overdueDirectives->count()">
            <x-slot:actions><a href="{{ route('directives.index') }}" wire:navigate class="{{ Ui::BTN_TINT }}">Directives</a></x-slot:actions>
            @forelse ($overdueDirectives as $d)
                <div class="px-5 py-3 border-b border-slate-100 last:border-0">
                    <p class="text-[13px] text-slate-700 line-clamp-2">{{ $d->body }}</p>
                    <p class="text-[11px] text-red-600 font-numeric">Due {{ Ui::date($d->due_on) }} · {{ $d->department?->name ?? 'No department' }}</p>
                </div>
            @empty
                <x-ui.empty icon="fa-check" title="No overdue directives" class="!py-8" />
            @endforelse
        </x-ui.card>
    </div>
</x-ui.page>
