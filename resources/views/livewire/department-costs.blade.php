@use('App\Support\Ui')
@use('App\Support\Money')
@php $sel = Ui::CONTROL.' appearance-none pr-8'; @endphp

<x-ui.page icon="fa-building-columns" title="Department costs" :subtitle="$period->label.' · approved, delivered and cancelled activities'" statsKey="deptStats">
    <x-slot:actions>
        @can('reports.export')
            <a href="{{ route('reports.export', ['report' => 'department-field-expenditure', 'format' => 'xlsx'] + $filters) }}" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-file-excel text-xs text-emerald-600"></i>Excel</a>
            <a href="{{ route('reports.export', ['report' => 'department-field-expenditure', 'format' => 'pdf'] + $filters) }}" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-file-pdf text-xs text-red-600"></i>PDF</a>
        @endcan
    </x-slot:actions>
    <x-slot:tabs>
        <div class="flex items-center gap-1 bg-gray-50 border border-gray-200 rounded-lg p-1 w-fit">
            @foreach (['requesting' => ['fa-building', 'By requesting department'], 'officer' => ['fa-id-badge', 'By officer\'s department']] as $key => [$icon, $label])
                <button type="button" wire:click="$set('lens', '{{ $key }}')" class="inline-flex items-center gap-2 px-4 py-1.5 text-xs font-semibold rounded-md transition {{ $lens === $key ? 'bg-emerald-600 text-white' : 'text-slate-500 hover:text-slate-800' }}"><i class="fa-solid {{ $icon }} text-[10px]"></i>{{ $label }}</button>
            @endforeach
        </div>
    </x-slot:tabs>
    <x-slot:filters>
        <p class="flex-1 min-w-[12rem] text-[12px] text-slate-500">{{ $lens === 'officer' ? 'Each officer\'s own costs, under the department they were in at the time. Shared costs are not included here.' : 'All costs of each activity, under the department that requested it, against its budget lines.' }}</p>
        <select wire:model.live="fy" class="{{ $sel }} shrink-0 sm:w-36" aria-label="Financial year"><option value="">FY {{ \App\Support\FinancialYear::current()->label() }}</option>@foreach ($years as $y)<option value="{{ $y }}">FY {{ $y }}</option>@endforeach</select>
        <select wire:model.live="quarter" class="{{ $sel }} shrink-0 sm:w-32" aria-label="Quarter"><option value="">Whole year</option>@foreach ([1, 2, 3, 4] as $q)<option value="{{ $q }}">Q{{ $q }}</option>@endforeach</select>
    </x-slot:filters>
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
                        @if ($lens === 'requesting')<th class="{{ Ui::TH }} text-right">Budget</th><th class="{{ Ui::TH }} text-right">Remaining</th>@endif
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
</x-ui.page>
