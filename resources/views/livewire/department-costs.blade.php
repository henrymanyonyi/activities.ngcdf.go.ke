@use('App\Support\Ui')
@use('App\Support\Money')
@php
    $sel = Ui::CONTROL.' appearance-none pr-8';
    $isBudget = $lens === 'budget';
    $kes = fn (?int $cents) => $cents === null ? '—' : Money::format($cents);
    $pct = fn (?int $p) => $p === null ? '—' : $p.'%';
@endphp

<x-ui.page icon="fa-building-columns" title="Department costs" :subtitle="$isBudget ? 'FY '.$fyLabel.' · allocation, commitments, spending and balance by requesting department' : $period->label.' · approved, delivered and cancelled activities'" statsKey="deptStats">
    <x-slot:actions>
        @can('reports.export')
            @php $report = $isBudget ? 'budget-position' : 'department-field-expenditure'; @endphp
            <a href="{{ route('reports.export', ['report' => $report, 'format' => 'xlsx'] + $filters) }}" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-file-excel text-xs text-emerald-600"></i>Excel</a>
            <a href="{{ route('reports.export', ['report' => $report, 'format' => 'pdf'] + $filters) }}" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-file-pdf text-xs text-red-600"></i>PDF</a>
        @endcan
    </x-slot:actions>
    <x-slot:tabs>
        <div class="flex items-center gap-1 bg-gray-50 border border-gray-200 rounded-lg p-1 w-fit">
            @foreach (['requesting' => ['fa-building', 'By requesting department'], 'officer' => ['fa-id-badge', 'By officer\'s department'], 'budget' => ['fa-scale-balanced', 'Budget position']] as $key => [$icon, $label])
                <button type="button" wire:click="$set('lens', '{{ $key }}')" class="inline-flex items-center gap-2 px-4 py-1.5 text-xs font-semibold rounded-md transition {{ $lens === $key ? 'bg-emerald-600 text-white' : 'text-slate-500 hover:text-slate-800' }}"><i class="fa-solid {{ $icon }} text-[10px]"></i>{{ $label }}</button>
            @endforeach
        </div>
    </x-slot:tabs>
    <x-slot:filters>
        @if ($isBudget)
            <p class="flex-1 min-w-[12rem] text-[12px] text-slate-500">Balance = allocation − spent − committed. Spent is what has been entered from Finance returns; committed is the planned amount of approved activities not yet spent. Pending requests are shown apart and are not deducted.</p>
            <select wire:model.live="department" class="{{ $sel }} shrink-0 sm:w-56" aria-label="Department"><option value="">All departments</option>@foreach ($rows as $r)@if ($r['department'])<option value="{{ $r['department']->id }}">{{ $r['department']->name }}</option>@endif @endforeach</select>
            <select wire:model.live="fy" class="{{ $sel }} shrink-0 sm:w-36" aria-label="Financial year"><option value="">FY {{ \App\Support\FinancialYear::current()->label() }}</option>@foreach ($years as $y)<option value="{{ $y }}">FY {{ $y }}</option>@endforeach</select>
        @else
            <p class="flex-1 min-w-[12rem] text-[12px] text-slate-500">{{ $lens === 'officer' ? 'Each officer\'s own costs, under the department they were in at the time. Shared costs are not included here.' : 'All costs of each activity, under the department that requested it. Budget and balance are for the financial year, as on the Budget position tab.' }}</p>
            <select wire:model.live="fy" class="{{ $sel }} shrink-0 sm:w-36" aria-label="Financial year"><option value="">FY {{ \App\Support\FinancialYear::current()->label() }}</option>@foreach ($years as $y)<option value="{{ $y }}">FY {{ $y }}</option>@endforeach</select>
            <select wire:model.live="quarter" class="{{ $sel }} shrink-0 sm:w-32" aria-label="Quarter"><option value="">Whole year</option>@foreach ([1, 2, 3, 4] as $q)<option value="{{ $q }}">Q{{ $q }}</option>@endforeach</select>
        @endif
    </x-slot:filters>

    @if ($isBudget)
        @php $t = $selectedRow ?? $totals; @endphp
        <x-slot:stats>
            <x-ui.stat :index="0" icon="fa-wallet" :label="$selected ? 'Allocation' : 'Board-wide allocation'" :value="$kes($t['allocation'])" money />
            <x-ui.stat :index="1" icon="fa-file-signature" label="Committed · spent" :value="$kes($t['committed'] + $t['spent'])" money tone="amber" :sub="'KES '.$kes($t['committed']).' committed, '.$kes($t['spent']).' spent'" />
            <x-ui.stat :index="2" icon="fa-scale-balanced" label="Available balance" :value="$kes($t['balance'])" money tone="blue" :sub="'utilisation '.$pct($t['utilisation']).' · spent '.$pct($t['spent_percent'])" />
            <x-ui.stat :index="3" icon="fa-hourglass-half" label="Pending requests" :value="$kes($t['pending'])" money tone="purple" :sub="$t['pending_count'].' '.Str::plural('memo', $t['pending_count']).' not yet decided; not deducted'" />
        </x-slot:stats>

        <x-ui.card title="Budget position by department" :count="$rows->count()">
            @if ($rows->isEmpty())
                <x-ui.empty icon="fa-scale-balanced" title="No allocations or activities this year" line="Department budgets are prepared under Budgeting › Department budgets and count once the CEO approves them.">
                    @canany(['budgets.prepare', 'budgets.approve'])
                        <a href="{{ route('budgets.index', ['fy' => $fyLabel]) }}" wire:navigate class="mt-3 {{ \App\Support\Ui::BTN_PRIMARY }}"><i class="fa-solid fa-wallet text-xs"></i>Go to Department budgets</a>
                    @endcanany
                </x-ui.empty>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="border-b border-slate-100"><tr>
                            <th class="{{ Ui::TH }}">Department</th><th class="{{ Ui::TH }} text-right">Allocation</th><th class="{{ Ui::TH }} text-right">Pending</th><th class="{{ Ui::TH }} text-right">Approved</th>
                            <th class="{{ Ui::TH }} text-right">Adjustments</th><th class="{{ Ui::TH }} text-right">Committed</th><th class="{{ Ui::TH }} text-right">Spent</th><th class="{{ Ui::TH }} text-right">Balance</th><th class="{{ Ui::TH }} text-right">Utilisation</th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($rows as $r)
                                <tr class="hover:bg-slate-50/60 {{ $selected && $r['department']?->id === $selected->id ? 'bg-emerald-50/50' : '' }}" wire:key="bp-{{ $r['department']?->id ?? 0 }}">
                                    <td class="{{ Ui::TD }} font-medium text-slate-700">
                                        @if ($r['department'])<button type="button" wire:click="$set('department', '{{ $r['department']->id }}')" class="hover:text-emerald-700 text-left">{{ $r['department']->name }}</button>@else No department @endif
                                    </td>
                                    <td class="{{ Ui::TD }} text-right font-numeric">{{ $kes($r['allocation']) }}</td>
                                    <td class="{{ Ui::TD }} text-right font-numeric text-purple-700">{{ $r['pending_count'] ? $kes($r['pending']) : '—' }}</td>
                                    <td class="{{ Ui::TD }} text-right font-numeric">{{ $kes($r['approved']) }}</td>
                                    <td class="{{ Ui::TD }} text-right font-numeric {{ $r['adjustments'] < 0 ? 'text-emerald-700' : ($r['adjustments'] > 0 ? 'text-amber-700' : '') }}">{{ $r['adjustments'] ? ($r['adjustments'] > 0 ? '+' : '').$kes($r['adjustments']) : '—' }}</td>
                                    <td class="{{ Ui::TD }} text-right font-numeric">{{ $kes($r['committed']) }}</td>
                                    <td class="{{ Ui::TD }} text-right font-numeric">{{ $kes($r['spent']) }}</td>
                                    <td class="{{ Ui::TD }} text-right font-numeric font-bold {{ ($r['balance'] ?? 0) < 0 ? 'text-red-600' : 'text-slate-800' }}">{{ $kes($r['balance']) }}</td>
                                    <td class="{{ Ui::TD }} text-right font-numeric">{{ $pct($r['utilisation']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t-2 border-slate-200 bg-slate-50"><tr>
                            <td class="px-4 py-3 text-[13px] font-bold">Board-wide</td>
                            @foreach (['allocation', 'pending', 'approved', 'adjustments', 'committed', 'spent', 'balance'] as $k)
                                <td class="px-4 py-3 text-right text-[13px] font-bold font-numeric">{{ $kes($totals[$k]) }}</td>
                            @endforeach
                            <td class="px-4 py-3 text-right text-[13px] font-bold font-numeric">{{ $pct($totals['utilisation']) }}</td>
                        </tr></tfoot>
                    </table>
                </div>
                <p class="px-5 py-3 text-[11px] text-slate-400 border-t border-slate-100">Approved is the amount the CEO authorised. Adjustments is the change in planned cost since approval (releases, team changes, extensions); a minus is a projected saving, not money returned. Pick a department for its activities.</p>
            @endif
        </x-ui.card>

        @if ($selected && $detail)
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                <x-ui.card :title="'Approved activities · '.$selected->name" icon="fa-circle-check" :count="$detail['approved']->count()" class="xl:col-span-2">
                    @if ($detail['approved']->isEmpty())
                        <x-ui.empty icon="fa-circle-check" title="No approved activities this year" />
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead class="border-b border-slate-100"><tr><th class="{{ Ui::TH }}">Activity</th><th class="{{ Ui::TH }}">Status</th><th class="{{ Ui::TH }} text-right">Approved</th><th class="{{ Ui::TH }} text-right">Planned now</th><th class="{{ Ui::TH }} text-right">Committed</th><th class="{{ Ui::TH }} text-right">Spent</th></tr></thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach ($detail['approved'] as $d)
                                        <tr wire:key="bpa-{{ $d['activity']->id }}">
                                            <td class="{{ Ui::TD }}"><a href="{{ route('activities.show', $d['activity']) }}" wire:navigate class="font-medium text-slate-700 hover:text-emerald-700">{{ $d['activity']->title }}</a><p class="text-[11px] text-slate-400 font-numeric">{{ $d['activity']->reference }} · {{ Ui::dateRange($d['activity']->start_date, $d['activity']->end_date) }}</p></td>
                                            <td class="{{ Ui::TD }}"><x-ui.status :status="$d['activity']->status" /></td>
                                            <td class="{{ Ui::TD }} text-right font-numeric">{{ $kes($d['approved']) }}</td>
                                            <td class="{{ Ui::TD }} text-right font-numeric">{{ $kes($d['planned']) }}@if ($d['adjustment'])<span class="block text-[11px] {{ $d['adjustment'] < 0 ? 'text-emerald-700' : 'text-amber-700' }}">{{ $d['adjustment'] > 0 ? '+' : '' }}{{ $kes($d['adjustment']) }}</span>@endif</td>
                                            <td class="{{ Ui::TD }} text-right font-numeric">{{ $kes($d['committed']) }}</td>
                                            <td class="{{ Ui::TD }} text-right font-numeric">{{ $kes($d['spent']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-ui.card>
                <div class="space-y-4">
                    <x-ui.card title="Pending requests" icon="fa-hourglass-half" :count="$detail['pending']->count()">
                        @forelse ($detail['pending'] as $d)
                            <a href="{{ route('activities.show', $d['activity']) }}" wire:navigate class="flex items-start justify-between gap-3 px-5 py-3 border-b border-slate-100 hover:bg-slate-50" wire:key="bpp-{{ $d['activity']->id }}">
                                <span class="min-w-0"><span class="block text-[13px] font-medium text-slate-700 truncate">{{ $d['activity']->title }}</span><span class="block text-[11px] text-slate-400">{{ $d['activity']->status->label() }} · {{ $d['activity']->currentHolder() }}</span></span>
                                <span class="text-[13px] font-numeric text-purple-700 whitespace-nowrap">{{ $kes($d['requested']) }}</span>
                            </a>
                        @empty
                            <p class="px-5 py-4 text-[13px] text-slate-400">No memo is waiting for a decision.</p>
                        @endforelse
                    </x-ui.card>
                    <x-ui.card title="Released by cancellations" icon="fa-ban" :count="$detail['cancelled']->count()">
                        @forelse ($detail['cancelled'] as $d)
                            <div class="flex items-start justify-between gap-3 px-5 py-3 border-b border-slate-100" wire:key="bpc-{{ $d['activity']->id }}">
                                <span class="min-w-0"><span class="block text-[13px] font-medium text-slate-700 truncate">{{ $d['activity']->title }}</span><span class="block text-[11px] text-slate-400">approved KES {{ $kes($d['approved']) }} · spent KES {{ $kes($d['spent']) }}</span></span>
                                <span class="text-[13px] font-numeric text-emerald-700 whitespace-nowrap">{{ $kes($d['released']) }}</span>
                            </div>
                        @empty
                            <p class="px-5 py-4 text-[13px] text-slate-400">No approved activity was cancelled.</p>
                        @endforelse
                    </x-ui.card>
                </div>
            </div>
        @endif
    @else
    <x-slot:stats>
        <x-ui.stat :index="0" icon="fa-bed" label="Board-wide DSA" :value="Money::format($totals['dsa'])" money tone="amber" />
        <x-ui.stat :index="1" icon="fa-plane" label="Board-wide travel" :value="Money::format($totals['travel'])" money tone="blue" />
        <x-ui.stat :index="2" icon="fa-coins" label="Grand total" :value="Money::format($totals['total'])" money :sub="'other costs '.Money::format($totals['other'])" />
        <x-ui.stat :index="3" icon="fa-scale-balanced" label="Planned · actual" :value="Money::format($totals['actual'])" money tone="purple" :sub="'of '.Money::format($totals['planned']).' planned'" />
    </x-slot:stats>

    <x-ui.card title="Departments" :count="$rows->count()">
        @if ($rows->isEmpty())
            <x-ui.empty icon="fa-building-columns" title="No costs in this period" />
        @else
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="border-b border-slate-100"><tr>
                        <th class="{{ Ui::TH }}">Department</th><th class="{{ Ui::TH }} text-right">Staff</th><th class="{{ Ui::TH }} text-right">Activities</th><th class="{{ Ui::TH }} text-right">Days</th>
                        <th class="{{ Ui::TH }} text-right">DSA</th><th class="{{ Ui::TH }} text-right">Travel</th><th class="{{ Ui::TH }} text-right">Other</th><th class="{{ Ui::TH }} text-right">Total</th>
                        @if ($lens === 'requesting')<th class="{{ Ui::TH }} text-right">Budget</th><th class="{{ Ui::TH }} text-right">Balance (FY)</th>@endif
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($rows as $r)
                            <tr class="hover:bg-slate-50/60">
                                <td class="{{ Ui::TD }} font-medium text-slate-700">
                                    @if ($lens === 'requesting' && $r['department'])<a href="{{ route('activities.index', ['department_id' => $r['department']->id] + array_filter($filters)) }}" wire:navigate class="hover:text-emerald-700">{{ $r['department']->name }}</a>@else{{ $r['department']?->name ?? 'Unassigned' }}@endif
                                </td>
                                <td class="{{ Ui::TD }} text-right font-numeric">{{ $r['staff_count'] }}</td>
                                <td class="{{ Ui::TD }} text-right font-numeric">{{ $r['activity_count'] }}</td>
                                <td class="{{ Ui::TD }} text-right font-numeric">{{ $r['days'] }}</td>
                                <td class="{{ Ui::TD }} text-right font-numeric">{{ Money::format($r['dsa']) }}</td>
                                <td class="{{ Ui::TD }} text-right font-numeric">{{ Money::format($r['travel']) }}</td>
                                <td class="{{ Ui::TD }} text-right font-numeric">{{ Money::format($r['other']) }}</td>
                                <td class="{{ Ui::TD }} text-right font-numeric font-bold text-slate-800">{{ Money::format($r['total']) }}</td>
                                @if ($lens === 'requesting')
                                    <td class="{{ Ui::TD }} text-right font-numeric">{{ $r['budget'] === null ? '—' : Money::format($r['budget']) }}</td>
                                    <td class="{{ Ui::TD }} text-right font-numeric {{ ($r['variance'] ?? 0) < 0 ? 'text-red-600 font-semibold' : '' }}">{{ $r['variance'] === null ? '—' : Money::format($r['variance']) }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t-2 border-slate-200 bg-slate-50"><tr>
                        <td class="px-4 py-3 text-[13px] font-bold">Total</td><td colspan="2"></td>
                        <td class="px-4 py-3 text-right text-[13px] font-bold font-numeric">{{ $totals['days'] }}</td>
                        <td class="px-4 py-3 text-right text-[13px] font-bold font-numeric">{{ Money::format($totals['dsa']) }}</td>
                        <td class="px-4 py-3 text-right text-[13px] font-bold font-numeric">{{ Money::format($totals['travel']) }}</td>
                        <td class="px-4 py-3 text-right text-[13px] font-bold font-numeric">{{ Money::format($totals['other']) }}</td>
                        <td class="px-4 py-3 text-right text-[13px] font-bold font-numeric">{{ Money::format($totals['total']) }}</td>
                        @if ($lens === 'requesting')<td colspan="2"></td>@endif
                    </tr></tfoot>
                </table>
            </div>
        @endif
    </x-ui.card>
    @endif
</x-ui.page>
