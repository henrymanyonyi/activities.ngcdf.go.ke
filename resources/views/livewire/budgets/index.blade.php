@use('App\Support\Ui')
@use('App\Support\Money')
@use('App\Enums\BudgetStatus')
@php
    $sel = Ui::CONTROL.' appearance-none pr-8';
    $kes = fn (?int $c) => $c === null ? 'Not set' : Money::format($c);
    $filters = ['' => ['All departments', null], BudgetStatus::Submitted->value => [BudgetStatus::Submitted->label(), $statusCounts[BudgetStatus::Submitted->value]], BudgetStatus::Returned->value => [BudgetStatus::Returned->label(), $statusCounts[BudgetStatus::Returned->value]], BudgetStatus::Draft->value => ['Drafts', $statusCounts[BudgetStatus::Draft->value]], BudgetStatus::Approved->value => ['Approved', $statusCounts[BudgetStatus::Approved->value]], 'none' => ['No budget yet', $withoutBudget]];
@endphp

<x-ui.page icon="fa-wallet" :title="$ownDepartment ? 'Workplan and budget' : 'Department budgets'" :subtitle="$ownDepartment ? 'Your department\'s workplan and budget estimates for the year. Prepare it, submit it to the CEO, and follow the review here. It becomes your approved budget only when the CEO approves it.' : 'Each department\'s workplan and budget for the year. Prepared as a draft by the department\'s Head or the Office of the CEO; counts only once the CEO approves it. Changes during the year are adjustments.'" statsKey="budgetStats">
    <x-slot:actions>
        <div class="w-40 shrink-0"><select wire:model.live="fy" class="{{ $sel }}" aria-label="Financial year">@foreach ($years as $y)<option value="{{ $y }}">FY {{ $y }}</option>@endforeach</select></div>
        <a href="{{ route('budgets.template') }}" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-file-excel text-xs text-emerald-700"></i>Workplan template</a>
        @unless ($ownDepartment)
            <a href="{{ route('budgets.adjustments') }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-sliders text-xs"></i>Adjustments</a>
        @endunless
        @if ($canPrepare && ! ($ownDepartment && ($rows->first()['budget'] ?? null)))
            <button type="button" wire:click="openPrepare" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-plus text-xs"></i>{{ $ownDepartment ? 'Prepare or import the workplan' : 'Prepare a budget' }}</button>
        @endif
    </x-slot:actions>

    @unless ($ownDepartment)
    <x-slot:tabs>
        <div role="tablist" class="flex flex-wrap gap-1.5">
            @foreach ($filters as $key => [$label, $count])
                @php $on = $status === $key; @endphp
                <button type="button" role="tab" aria-selected="{{ $on ? 'true' : 'false' }}" wire:click="$set('status', '{{ $key }}')"
                    class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-[13px] font-semibold border transition {{ $on ? 'bg-emerald-600 border-emerald-600 text-white' : 'bg-white border-slate-200 text-slate-600 hover:border-emerald-300 hover:text-emerald-700' }}">
                    {{ $label }}
                    @if ($count !== null)<span class="font-numeric px-1.5 py-0.5 rounded-full text-[10px] font-bold {{ $on ? 'bg-white/20 text-white' : ($key === BudgetStatus::Submitted->value && $count > 0 ? 'bg-amber-400 text-emerald-950' : 'bg-slate-100 text-slate-500') }}">{{ $count }}</span>@endif
                </button>
            @endforeach
        </div>
    </x-slot:tabs>
    @endunless

    <x-slot:stats>
        <x-ui.stat :index="0" icon="fa-wallet" :label="'Approved allocation · FY '.$fy" :value="$kes($totals['allocation'])" :money="$totals['allocation'] !== null" sub="approved budgets, with approved adjustments" />
        <x-ui.stat :index="1" icon="fa-receipt" tone="blue" label="Spent" :value="$kes($totals['spent'])" money sub="actual amounts from Finance returns" />
        <x-ui.stat :index="2" icon="fa-lock" tone="amber" label="Committed" :value="$kes($totals['committed'])" money sub="approved activities not yet spent" />
        <x-ui.stat :index="3" icon="fa-scale-balanced" :tone="($totals['balance'] ?? 0) < 0 ? 'red' : 'emerald'" label="Available balance" :value="$kes($totals['balance'])" :money="$totals['balance'] !== null" sub="allocation − spent − committed" />
    </x-slot:stats>

    <section class="{{ Ui::CARD }}">
        @if ($rows->isEmpty())
            <x-ui.empty icon="fa-wallet" title="No departments match" line="Choose another status or financial year." />
        @else
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="border-b border-slate-100"><tr>
                        <th class="{{ Ui::TH }}">Department</th><th class="{{ Ui::TH }}">Status</th>
                        <th class="{{ Ui::TH }} text-right">As approved</th><th class="{{ Ui::TH }} text-right">Adjustments</th><th class="{{ Ui::TH }} text-right">Allocation now</th>
                        <th class="{{ Ui::TH }} text-right">Spent</th><th class="{{ Ui::TH }} text-right">Committed</th><th class="{{ Ui::TH }} text-right">Balance</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($rows as $r)
                            @php $b = $r['budget']; $p = $r['position']; $approved = $b?->status === BudgetStatus::Approved; @endphp
                            <tr wire:key="d-{{ $r['department']->id }}" class="hover:bg-slate-50/60">
                                <td class="{{ Ui::TD }}">
                                    @if ($b)
                                        <a href="{{ route('budgets.show', $b) }}" wire:navigate class="font-semibold text-emerald-700 hover:underline">{{ $r['department']->name }}</a>
                                    @else
                                        <p class="font-semibold text-slate-700">{{ $r['department']->name }}</p>
                                    @endif
                                    @if ($b)<p class="text-[11px] text-slate-400">{{ $b->lines_count }} {{ Str::plural('line', $b->lines_count) }}@if (! $approved && $b->preparer) · prepared by {{ $b->preparer->name }}@endif</p>@endif
                                </td>
                                <td class="{{ Ui::TD }}">
                                    @if ($b)
                                        <div class="flex items-center gap-1.5">
                                            <x-ui.status :status="$b->status" />
                                            @if ($canApprove && $b->status === BudgetStatus::Submitted)
                                                <a href="{{ route('budgets.show', $b) }}" wire:navigate class="{{ Ui::BTN_TINT }}"><i class="fa-solid fa-magnifying-glass text-[9px]"></i>Review and approve</a>
                                            @elseif ($ownDepartment && $b->status->isEditable())
                                                <a href="{{ route('budgets.show', $b) }}" wire:navigate class="{{ Ui::BTN_TINT }}"><i class="fa-solid fa-pen text-[9px]"></i>Open</a>
                                            @endif
                                        </div>
                                    @else
                                        <div class="flex items-center gap-1.5"><x-ui.badge>No budget</x-ui.badge>@if ($canPrepare)<button type="button" wire:click="openPrepare({{ $r['department']->id }})" class="{{ Ui::BTN_TINT }}"><i class="fa-solid fa-plus text-[9px]"></i>Prepare or import</button>@endif</div>
                                    @endif
                                </td>
                                @if ($b)
                                    <td class="{{ Ui::TD }} text-right">
                                        <x-ui.money :value="$r['totals']['original']" :muted="! $approved" />
                                        @unless ($approved)<p class="text-[10px] text-slate-400">not yet counted</p>@endunless
                                    </td>
                                    <td class="{{ Ui::TD }} text-right font-numeric {{ $r['totals']['adjustments'] < 0 ? 'text-red-600' : '' }}">{{ $r['totals']['adjustments'] === 0 ? '' : ($r['totals']['adjustments'] > 0 ? '+' : '−').Money::format(abs($r['totals']['adjustments'])) }}</td>
                                    <td class="{{ Ui::TD }} text-right font-semibold">@if ($approved)<x-ui.money :value="$r['totals']['current']" />@endif</td>
                                @else
                                    <td class="{{ Ui::TD }}"></td><td class="{{ Ui::TD }}"></td><td class="{{ Ui::TD }}"></td>
                                @endif
                                <td class="{{ Ui::TD }} text-right">@if ($p && $p['spent'])<x-ui.money :value="$p['spent']" />@endif</td>
                                <td class="{{ Ui::TD }} text-right">@if ($p && $p['committed'])<x-ui.money :value="$p['committed']" />@endif</td>
                                <td class="{{ Ui::TD }} text-right font-semibold {{ ($p['balance'] ?? 0) < 0 ? 'text-red-600' : '' }}">@if ($p && $p['balance'] !== null)<x-ui.money :value="$p['balance']" />@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
    <p class="text-[12px] text-slate-400">Spent and committed are from activities in the year whether or not the department has an approved budget. A draft or a budget awaiting approval counts for nothing until the CEO approves it.</p>

    <x-ui.modal show="showPrepare" :open="$showPrepare" size="slim" icon="fa-wallet" :title="$ownDepartment ? 'Prepare your workplan and budget' : 'Prepare a department budget'" subtitle="It starts as a draft. Add its activities and their costs, typed in or from the workplan template, then submit it to the CEO for approval.">
        <x-ui.field label="Department" for="p-d" error="prepareDepartment" required>
            <select id="p-d" wire:model="prepareDepartment" class="{{ $sel }}" @disabled($ownDepartment)><option value="">Choose…</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
        </x-ui.field>
        <x-ui.field label="Financial year" for="p-y" error="prepareYear" required>
            <select id="p-y" wire:model="prepareYear" class="{{ $sel }}">@foreach ($years as $y)<option value="{{ $y }}">FY {{ $y }}</option>@endforeach</select>
        </x-ui.field>
        <x-ui.field label="Workplan in Excel (optional)" for="p-f" error="prepareFile" hint="Already have it in the workplan template? Attach it and its rows become the lines. Download the template with the Workplan template button.">
            <input id="p-f" type="file" wire:model="prepareFile" accept=".xlsx,.xls,.csv" class="block w-full text-[13px] text-slate-600 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:font-semibold">
            <div wire:loading wire:target="prepareFile" class="text-[11px] text-slate-400">Uploading…</div>
        </x-ui.field>
        <x-ui.field label="Notes" for="p-n" error="prepareNotes" hint="Optional: the source, such as the approved printed estimates.">
            <textarea id="p-n" wire:model="prepareNotes" rows="3" maxlength="2000" class="{{ Ui::CONTROL }}"></textarea>
        </x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showPrepare', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="prepare" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="prepare,prepareFile"><i class="fa-solid fa-pen-ruler text-xs"></i>{{ $prepareFile ? 'Create draft and import' : 'Create draft' }}</button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.page>
