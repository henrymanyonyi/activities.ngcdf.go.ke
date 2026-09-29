@use('App\Support\Ui')
@php
    $sel = Ui::CONTROL.' appearance-none pr-8';
    $columns = $definition->columns();
    $numeric = fn ($type) => in_array($type, ['money', 'int', 'percent'], true);
    $wants = $definition->filters();
@endphp

<x-ui.page :icon="$definition->icon()" :title="$definition->title()" :subtitle="$definition->description()" scroll="inner">
    <x-slot:pills><span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 text-[11px] font-semibold">{{ $definition->subtitle($this->filters()) }}</span></x-slot:pills>
    <x-slot:actions>
        <a href="{{ route('reports.index') }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-arrow-left text-xs"></i>Reports</a>
        @can('reports.export')
            <a href="{{ route('reports.export', ['report' => $definition->key(), 'format' => 'xlsx'] + $exportQuery) }}" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-file-excel text-xs text-emerald-600"></i>Excel</a>
            <a href="{{ route('reports.export', ['report' => $definition->key(), 'format' => 'pdf'] + $exportQuery) }}" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-file-pdf text-xs text-red-600"></i>PDF</a>
            <a href="{{ route('reports.export', ['report' => $definition->key(), 'format' => 'print'] + $exportQuery) }}" target="_blank" rel="noopener" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-print text-xs"></i>Print</a>
        @endcan
    </x-slot:actions>

    @if ($wants !== [] && $wants !== ['register'])
        <x-slot:filters>
            @if (in_array('period', $wants))
                <select wire:model.live="fy" class="{{ $sel }} shrink-0 sm:w-36" aria-label="Financial year"><option value="">FY {{ \App\Support\FinancialYear::current()->label() }}</option>@foreach ($years as $y)<option value="{{ $y }}">FY {{ $y }}</option>@endforeach</select>
                <select wire:model.live="quarter" class="{{ $sel }} shrink-0 sm:w-32" aria-label="Quarter"><option value="">Whole year</option>@foreach ([1, 2, 3, 4] as $q)<option value="{{ $q }}">Q{{ $q }}</option>@endforeach</select>
                <input type="date" wire:model.live="from" class="{{ Ui::CONTROL }} shrink-0 sm:w-44" aria-label="From">
                <input type="date" wire:model.live="to" class="{{ Ui::CONTROL }} shrink-0 sm:w-44" aria-label="To">
            @endif
            @if (in_array('department', $wants))
                <select wire:model.live="department_id" class="{{ $sel }} shrink-0 sm:w-56" aria-label="Department"><option value="">All departments</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
            @endif
            @if (in_array('week', $wants))
                <input type="date" wire:model.live="week" class="{{ Ui::CONTROL }} shrink-0 sm:w-44" aria-label="Any day in the week">
            @endif
            <span class="ml-auto text-[11px] text-slate-400">A date range overrides the year and quarter.</span>
        </x-slot:filters>
    @endif

    <div class="flex-1 min-h-0 flex flex-col {{ Ui::CARD }}">
        <div class="{{ Ui::CAPTION }}"><h2 class="{{ Ui::MICRO }}">Results</h2><span class="text-[11px] text-slate-500 font-numeric">{{ $rows->count() }} rows</span></div>
        @if ($rows->isEmpty())
            <x-ui.empty icon="fa-file-circle-question" title="Nothing to report" line="No records for the selected filters." />
        @else
            <div class="flex-1 min-h-0 overflow-auto">
                <table class="w-full">
                    <thead class="sticky top-0 z-10 bg-white border-b border-slate-100"><tr>
                        @foreach ($columns as [$label, $type])<th class="{{ Ui::TH }} {{ $numeric($type) ? 'text-right' : '' }}">{{ $label }}</th>@endforeach
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($rows as $row)
                            <tr class="hover:bg-slate-50/60">
                                @foreach ($columns as $key => [$label, $type])
                                    <td class="{{ Ui::TD }} {{ $numeric($type) || $type === 'date' ? 'font-numeric whitespace-nowrap' : '' }} {{ $numeric($type) ? 'text-right' : '' }}">
                                        @if ($loop->first && ! empty($row['_activity_id']))
                                            <a href="{{ route('activities.show', $row['_activity_id']) }}" wire:navigate class="text-emerald-700 hover:underline">{{ $definition->format($row[$key] ?? null, $type) }}</a>
                                        @else
                                            {{ $definition->format($row[$key] ?? null, $type) }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                    @if ($totals)
                        <tfoot class="sticky bottom-0 bg-slate-50 border-t-2 border-slate-200"><tr>
                            @foreach (array_keys($columns) as $i => $key)
                                <td class="px-4 py-3 text-[13px] font-bold font-numeric {{ isset($totals[$key]) ? 'text-right' : '' }}">{{ $i === 0 ? 'Total' : (isset($totals[$key]) ? \App\Support\Money::format($totals[$key]) : '') }}</td>
                            @endforeach
                        </tr></tfoot>
                    @endif
                </table>
            </div>
        @endif
    </div>
</x-ui.page>
