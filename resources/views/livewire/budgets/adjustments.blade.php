@use('App\Support\Ui')
@use('App\Enums\BudgetStatus')
@php
    $sel = Ui::CONTROL.' appearance-none pr-8';
    $filters = [BudgetStatus::Submitted->value => BudgetStatus::Submitted->label(), BudgetStatus::Returned->value => BudgetStatus::Returned->label(), BudgetStatus::Draft->value => 'Drafts', BudgetStatus::Approved->value => 'Approved', '' => 'All'];
@endphp

<x-ui.page icon="fa-sliders" title="Budget adjustments" subtitle="Supplementary allocations, reductions and reallocations to approved department budgets during the year. Each counts once the CEO approves it." scroll="inner">
    <x-slot:actions>
        <div class="w-40 shrink-0"><select wire:model.live="fy" class="{{ $sel }}" aria-label="Financial year">@foreach ($years as $y)<option value="{{ $y }}">FY {{ $y }}</option>@endforeach</select></div>
        <a href="{{ route('budgets.index', ['fy' => $fy]) }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-wallet text-xs"></i>Department budgets</a>
    </x-slot:actions>

    <x-slot:tabs>
        <div role="tablist" class="flex flex-wrap gap-1.5">
            @foreach ($filters as $key => $label)
                @php $on = (string) $status === $key; @endphp
                <button type="button" role="tab" aria-selected="{{ $on ? 'true' : 'false' }}" wire:click="$set('status', '{{ $key }}')"
                    class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-[13px] font-semibold border transition {{ $on ? 'bg-emerald-600 border-emerald-600 text-white' : 'bg-white border-slate-200 text-slate-600 hover:border-emerald-300 hover:text-emerald-700' }}">
                    {{ $label }}
                    @if ($key !== '')<span class="font-numeric px-1.5 py-0.5 rounded-full text-[10px] font-bold {{ $on ? 'bg-white/20 text-white' : ($key === BudgetStatus::Submitted->value && $counts[$key] > 0 ? 'bg-amber-400 text-emerald-950' : 'bg-slate-100 text-slate-500') }}">{{ $counts[$key] }}</span>@endif
                </button>
            @endforeach
        </div>
    </x-slot:tabs>

    <section class="{{ Ui::CARD }} flex-1 min-h-0 overflow-y-auto">
        @if ($adjustments->isEmpty())
            <x-ui.empty icon="fa-sliders" title="No adjustments here" line="Propose an adjustment from an approved department budget." />
        @else
            <table class="w-full">
                <thead class="border-b border-slate-100 sticky top-0 bg-white"><tr>
                    <th class="{{ Ui::TH }}">Department</th><th class="{{ Ui::TH }}">Adjustment</th><th class="{{ Ui::TH }}">Status</th><th class="{{ Ui::TH }}">Proposed</th><th class="{{ Ui::TH }}"><span class="sr-only">Open</span></th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($adjustments as $adj)
                        <tr wire:key="a-{{ $adj->id }}" class="hover:bg-slate-50/60">
                            <td class="{{ Ui::TD }} font-semibold text-slate-700">{{ $adj->budget->department?->name }}</td>
                            <td class="{{ Ui::TD }}">
                                <p class="font-semibold text-slate-700">{{ $adj->kindLabel() }}</p>
                                <p>{{ $adj->summary() }}</p>
                                <p class="text-[12px] text-slate-400">{{ Str::limit($adj->reason, 140) }}</p>
                            </td>
                            <td class="{{ Ui::TD }}"><x-ui.status :status="$adj->status" /></td>
                            <td class="{{ Ui::TD }} whitespace-nowrap">{{ $adj->preparer?->name }}<p class="text-[11px] text-slate-400 font-numeric">{{ $adj->created_at->format('d M Y') }}</p></td>
                            <td class="{{ Ui::TD }} text-right"><a href="{{ route('budgets.show', $adj->department_budget_id) }}" wire:navigate class="{{ Ui::BTN_TINT }}">Open</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</x-ui.page>
