@use('App\Support\Ui')
@use('App\Support\Money')
@use('App\Enums\BudgetStatus')
@use('App\Models\BudgetAdjustment')
@php
    $sel = Ui::CONTROL.' appearance-none pr-8';
    $b = $budget;
    $me = auth()->id();
    $status = $b->status;
    $editable = $status->isEditable() && $canPrepare;
    $approved = $status === BudgetStatus::Approved;
    // The CEO approves a draft the CEO prepared directly; anyone else's draft is submitted first.
    $ceoOwnDraft = $canApprove && $status === BudgetStatus::Draft && $b->prepared_by === $me;
    $signed = fn (int $c) => $c === 0 ? '' : ($c > 0 ? '+' : '−').Money::format(abs($c));
@endphp

<x-ui.page icon="fa-wallet" :title="$b->department?->name.' budget · FY '.$b->financial_year" :subtitle="$approved ? 'Approved by the CEO. Changes during the year are made by adjustments, each approved by the CEO.' : 'Counts for nothing until the CEO approves it.'">
    <x-slot:pills><x-ui.status :status="$status" /></x-slot:pills>
    <x-slot:actions>
        <a href="{{ route('budgets.index', ['fy' => $b->financial_year]) }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-arrow-left text-xs"></i>All budgets</a>
        @if ($editable)
            <button type="button" wire:click="ask('discard', 'budget')" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-trash-can text-xs"></i>Discard draft</button>
            @if ($ceoOwnDraft)
                <button type="button" wire:click="ask('approve', 'budget')" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-circle-check text-xs"></i>Approve budget</button>
            @elseif (! $canApprove)
                <button type="button" wire:click="ask('submit', 'budget')" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-paper-plane text-xs"></i>{{ $status === BudgetStatus::Returned ? 'Resubmit to the CEO' : 'Submit to the CEO' }}</button>
            @endif
        @endif
        @if ($status === BudgetStatus::Submitted && $canApprove)
            <button type="button" wire:click="openReturn('budget')" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-reply text-xs"></i>Return for correction</button>
            <button type="button" wire:click="ask('approve', 'budget')" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-circle-check text-xs"></i>Approve budget</button>
        @endif
        @if ($approved && $canPrepare)
            <button type="button" wire:click="openAdjustment" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-plus text-xs"></i>Propose adjustment</button>
        @endif
    </x-slot:actions>

    @if ($status === BudgetStatus::Returned)
        <div class="px-4 py-3 rounded-lg bg-orange-50 border border-orange-200 text-orange-800 text-[13px]" role="alert">
            <p class="font-semibold"><i class="fa-solid fa-reply mr-1"></i>Returned for correction by {{ $b->returner?->name ?? 'the CEO' }} on {{ $b->returned_at?->format('d M Y') }}</p>
            <p class="mt-1 whitespace-pre-line">{{ $b->return_reason }}</p>
            @if ($canPrepare && ! $canApprove)<p class="mt-1 text-[12px]">Correct the lines, then press <strong>Resubmit to the CEO</strong>.</p>@endif
        </div>
    @elseif ($status === BudgetStatus::Submitted && ! $canApprove)
        <div class="px-4 py-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-[13px]"><i class="fa-solid fa-hourglass-half mr-1"></i>With the CEO for approval since {{ $b->submitted_at?->format('d M Y') }}. It cannot be changed until the CEO approves or returns it.</div>
    @elseif ($canApprove && $status->isEditable() && ! $ceoOwnDraft)
        <div class="px-4 py-3 rounded-lg bg-slate-50 border border-slate-200 text-slate-600 text-[13px]"><i class="fa-solid fa-pen-ruler mr-1"></i>Being prepared by {{ $b->preparer?->name ?? 'the Office of the CEO' }}. It comes to you for approval when it is submitted.</div>
    @endif

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4 items-start">
        <div class="xl:col-span-2 flex flex-col gap-4">
            <x-ui.card title="Budget lines" icon="fa-list" :count="$lines->count()">
                @if ($editable)
                    <x-slot:actions><button type="button" wire:click="openLine" class="{{ Ui::BTN_TINT }}"><i class="fa-solid fa-plus text-[9px]"></i>Add line</button></x-slot:actions>
                @endif
                @if ($lines->isEmpty())
                    <x-ui.empty icon="fa-list" title="No lines yet" :line="$editable ? 'Add each vote or sub-vote with its allocation for the year.' : null" class="!py-8" />
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="border-b border-slate-100"><tr>
                                <th class="{{ Ui::TH }}">Code</th><th class="{{ Ui::TH }}">Line</th>
                                <th class="{{ Ui::TH }} text-right">{{ $approved ? 'As approved' : 'Allocation' }}</th>
                                @if ($approved)<th class="{{ Ui::TH }} text-right">Adjustments</th><th class="{{ Ui::TH }} text-right">Allocation now</th>@endif
                                @if ($editable)<th class="{{ Ui::TH }}"><span class="sr-only">Actions</span></th>@endif
                            </tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($lines as $line)
                                    @php $a = $amounts[$line->id]; @endphp
                                    <tr wire:key="l-{{ $line->id }}">
                                        <td class="{{ Ui::TD }} font-numeric">{{ $line->code }}</td>
                                        <td class="{{ Ui::TD }}">{{ $line->name }}</td>
                                        <td class="{{ Ui::TD }} text-right"><x-ui.money :value="$a['original']" /></td>
                                        @if ($approved)
                                            <td class="{{ Ui::TD }} text-right font-numeric {{ $a['adjustments'] < 0 ? 'text-red-600' : 'text-emerald-700' }}">{{ $signed($a['adjustments']) }}</td>
                                            <td class="{{ Ui::TD }} text-right font-semibold"><x-ui.money :value="$a['current']" /></td>
                                        @endif
                                        @if ($editable)
                                            <td class="{{ Ui::TD }} text-right whitespace-nowrap">
                                                <button type="button" wire:click="openLine({{ $line->id }})" class="{{ Ui::BTN_ICON }}" aria-label="Edit line"><i class="fa-solid fa-pen text-xs"></i></button>
                                                <button type="button" wire:click="removeLine({{ $line->id }})" class="{{ Ui::BTN_ICON_DANGER }}" aria-label="Remove line"><i class="fa-solid fa-trash-can text-xs"></i></button>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                                <tr class="bg-slate-50/60">
                                    <td class="{{ Ui::TD }} font-semibold text-slate-700" colspan="2">Total</td>
                                    <td class="{{ Ui::TD }} text-right font-semibold"><x-ui.money :value="$totals['original']" /></td>
                                    @if ($approved)
                                        <td class="{{ Ui::TD }} text-right font-numeric font-semibold {{ $totals['adjustments'] < 0 ? 'text-red-600' : 'text-emerald-700' }}">{{ $signed($totals['adjustments']) }}</td>
                                        <td class="{{ Ui::TD }} text-right font-bold"><x-ui.money :value="$totals['current']" /></td>
                                    @endif
                                    @if ($editable)<td class="{{ Ui::TD }}"></td>@endif
                                </tr>
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>

            @if ($approved)
                <x-ui.card title="Adjustments during the year" icon="fa-sliders" :count="$adjustments->count()">
                    @if ($adjustments->isEmpty())
                        <x-ui.empty icon="fa-sliders" title="No adjustments" line="A supplementary allocation, a reduction or a reallocation between lines. Each counts once the CEO approves it." class="!py-8" />
                    @else
                        <div class="divide-y divide-slate-100">
                            @foreach ($adjustments as $adj)
                                @php
                                    $adjEditable = $adj->status->isEditable() && $canPrepare;
                                    $adjOwnDraft = $canApprove && $adj->status === BudgetStatus::Draft && $adj->prepared_by === $me;
                                @endphp
                                <div class="px-5 py-3.5 flex flex-col sm:flex-row sm:items-start gap-3" wire:key="adj-{{ $adj->id }}">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-[13px] font-semibold text-slate-700">{{ $adj->kindLabel() }}</span>
                                            <x-ui.status :status="$adj->status" />
                                        </div>
                                        <p class="text-[13px] text-slate-600 mt-0.5">{{ $adj->summary() }}</p>
                                        <p class="text-[12px] text-slate-500 mt-0.5"><span class="font-semibold">Reason:</span> {{ $adj->reason }}</p>
                                        <p class="text-[11px] text-slate-400 mt-1">
                                            Proposed by {{ $adj->preparer?->name ?? 'unknown' }} on {{ $adj->created_at->format('d M Y') }}
                                            @if ($adj->approved_at) · approved by {{ $adj->approver?->name ?? 'the CEO' }} on {{ $adj->approved_at->format('d M Y') }}@endif
                                        </p>
                                        @if ($adj->status === BudgetStatus::Returned)
                                            <p class="text-[12px] text-orange-700 bg-orange-50 border border-orange-200 rounded-lg px-2.5 py-1.5 mt-1.5"><i class="fa-solid fa-reply mr-1"></i>Returned by {{ $adj->returner?->name ?? 'the CEO' }}: {{ $adj->return_reason }}</p>
                                        @endif
                                    </div>
                                    <div class="flex flex-wrap gap-1.5 sm:justify-end shrink-0">
                                        @if ($adjEditable)
                                            <button type="button" wire:click="openAdjustment({{ $adj->id }})" class="{{ Ui::BTN_TINT }}">Edit</button>
                                            <button type="button" wire:click="ask('discard', 'adjustment', {{ $adj->id }})" class="{{ Ui::BTN_TINT }}">Discard</button>
                                            @if ($adjOwnDraft)
                                                <button type="button" wire:click="ask('approve', 'adjustment', {{ $adj->id }})" class="{{ Ui::BTN_TINT }}">Approve</button>
                                            @elseif (! $canApprove)
                                                <button type="button" wire:click="ask('submit', 'adjustment', {{ $adj->id }})" class="{{ Ui::BTN_TINT }}">{{ $adj->status === BudgetStatus::Returned ? 'Resubmit to the CEO' : 'Submit to the CEO' }}</button>
                                            @endif
                                        @endif
                                        @if ($adj->status === BudgetStatus::Submitted && $canApprove)
                                            <button type="button" wire:click="openReturn('adjustment', {{ $adj->id }})" class="{{ Ui::BTN_TINT }}">Return</button>
                                            <button type="button" wire:click="ask('approve', 'adjustment', {{ $adj->id }})" class="{{ Ui::BTN_TINT }}">Approve</button>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-ui.card>
            @endif
        </div>

        <div class="flex flex-col gap-4">
            @if ($figures)
                <x-ui.card :title="'Position · FY '.$b->financial_year" icon="fa-scale-balanced" padded>
                    <dl class="space-y-2 text-[13px]">
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Allocation now</dt><dd class="font-semibold"><x-ui.money :value="$figures['allocation'] ?? 0" /></dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Spent</dt><dd><x-ui.money :value="$figures['spent']" /></dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Committed</dt><dd><x-ui.money :value="$figures['committed']" /></dd></div>
                        <div class="flex justify-between gap-3 pt-2 border-t border-slate-100"><dt class="font-semibold text-slate-700">Available balance</dt><dd class="font-bold {{ ($figures['balance'] ?? 0) < 0 ? 'text-red-600' : 'text-emerald-700' }}"><x-ui.money :value="$figures['balance'] ?? 0" /></dd></div>
                        <div class="flex justify-between gap-3 text-[12px]"><dt class="text-slate-400">Memos awaiting decision ({{ $figures['pending_count'] }})</dt><dd class="text-slate-400"><x-ui.money :value="$figures['pending']" muted /></dd></div>
                        @if ($totals['pending'] !== 0)
                            <div class="flex justify-between gap-3 text-[12px]"><dt class="text-amber-700">Adjustments awaiting approval</dt><dd class="font-numeric text-amber-700">{{ $signed($totals['pending']) }}</dd></div>
                        @endif
                    </dl>
                    <p class="text-[11px] text-slate-400 mt-3">Spent is only what has been entered from Finance returns. Pending memos and adjustments are not deducted.</p>
                </x-ui.card>
            @endif

            <x-ui.card title="Record" icon="fa-clock-rotate-left" padded>
                <dl class="space-y-2 text-[13px]">
                    <div><dt class="{{ Ui::MICRO }}">Prepared by</dt><dd class="text-slate-700">{{ $b->preparer?->name ?? 'Entered before budget approval was introduced' }} · {{ $b->created_at->format('d M Y') }}</dd></div>
                    @if ($b->submitted_at)<div><dt class="{{ Ui::MICRO }}">Submitted to the CEO</dt><dd class="text-slate-700">{{ $b->submitter?->name }} · {{ $b->submitted_at->format('d M Y H:i') }}</dd></div>@endif
                    @if ($b->returned_at)<div><dt class="{{ Ui::MICRO }}">Last returned for correction</dt><dd class="text-slate-700">{{ $b->returner?->name }} · {{ $b->returned_at->format('d M Y H:i') }}</dd></div>@endif
                    @if ($b->approved_at)<div><dt class="{{ Ui::MICRO }}">Approved</dt><dd class="text-slate-700">{{ $b->approver?->name ?? 'Recorded as approved' }} · {{ $b->approved_at->format('d M Y H:i') }}</dd></div>@endif
                    <div>
                        <dt class="{{ Ui::MICRO }} flex items-center justify-between">Notes @if ($editable)<button type="button" wire:click="openNotes" class="text-emerald-700 normal-case tracking-normal text-[11px] font-semibold hover:underline">Edit</button>@endif</dt>
                        <dd class="text-slate-600 whitespace-pre-line">{{ $b->notes ?: 'None' }}</dd>
                    </div>
                </dl>
                <p class="text-[11px] text-slate-400 mt-3">Every change is kept in the audit trail.</p>
            </x-ui.card>
        </div>
    </div>

    {{-- Budget line --}}
    <x-ui.modal show="showLine" :open="$showLine" size="slim" icon="fa-list" :title="$lineId ? 'Edit budget line' : 'Add a budget line'" subtitle="A vote or sub-vote and its allocation for the year.">
        <div class="grid grid-cols-3 gap-3">
            <x-ui.field label="Code" for="l-c" error="lineCode"><input id="l-c" type="text" wire:model="lineCode" maxlength="40" class="{{ Ui::CONTROL }} font-numeric" placeholder="2210300"></x-ui.field>
            <x-ui.field label="Name" for="l-n" error="lineName" required class="col-span-2"><input id="l-n" type="text" wire:model="lineName" maxlength="255" class="{{ Ui::CONTROL }}" placeholder="Domestic travel and subsistence"></x-ui.field>
        </div>
        <x-ui.field label="Allocation (KES)" for="l-a" error="lineAmount" required><input id="l-a" type="text" inputmode="decimal" wire:model="lineAmount" class="{{ Ui::CONTROL }} font-numeric"></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showLine', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="saveLine" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="saveLine">Save line</button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Notes --}}
    <x-ui.modal show="showNotes" :open="$showNotes" size="slim" icon="fa-note-sticky" title="Notes">
        <x-ui.field label="Notes" for="n-n" error="notes"><textarea id="n-n" wire:model="notes" rows="4" maxlength="2000" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showNotes', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="saveNotes" class="{{ Ui::BTN_PRIMARY }}">Save</button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Adjustment --}}
    <x-ui.modal show="showAdjustment" :open="$showAdjustment" icon="fa-sliders" :title="$adjustmentId ? 'Edit adjustment' : 'Propose a budget adjustment'" subtitle="Saved as a draft. It changes the budget only once the CEO approves it.">
        <x-ui.field label="Kind of adjustment" for="a-k" error="adjKind" required>
            <select id="a-k" wire:model.live="adjKind" class="{{ $sel }}">@foreach (BudgetAdjustment::KINDS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
        </x-ui.field>

        @if ($adjKind === BudgetAdjustment::KIND_REALLOCATION)
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <x-ui.field label="Take from" for="a-f" required>
                    <select id="a-f" wire:model="adjFrom" class="{{ $sel }}"><option value="">Choose a line…</option>@foreach ($lines as $line)<option value="{{ $line->id }}">{{ $line->label(withYear: false) }} · KES {{ Money::format($amounts[$line->id]['current']) }}</option>@endforeach</select>
                </x-ui.field>
                <x-ui.field label="Give to" for="a-t" required>
                    <select id="a-t" wire:model="adjLine" class="{{ $sel }}"><option value="">Choose a line…</option>@foreach ($lines as $line)<option value="{{ $line->id }}">{{ $line->label(withYear: false) }}</option>@endforeach</select>
                </x-ui.field>
            </div>
        @else
            <x-ui.field :label="$adjKind === BudgetAdjustment::KIND_REDUCTION ? 'Line to reduce' : 'Line to increase'" for="a-l" required>
                <select id="a-l" wire:model.live="adjLine" class="{{ $sel }}">
                    <option value="">{{ $adjKind === BudgetAdjustment::KIND_SUPPLEMENTARY ? 'A new line (name it below)' : 'Choose a line…' }}</option>
                    @foreach ($lines as $line)<option value="{{ $line->id }}">{{ $line->label(withYear: false) }} · KES {{ Money::format($amounts[$line->id]['current']) }}</option>@endforeach
                </select>
            </x-ui.field>
            @if ($adjKind === BudgetAdjustment::KIND_SUPPLEMENTARY && $adjLine === '')
                <div class="grid grid-cols-3 gap-3">
                    <x-ui.field label="New line code" for="a-nc" error="adjNewCode"><input id="a-nc" type="text" wire:model="adjNewCode" maxlength="40" class="{{ Ui::CONTROL }} font-numeric"></x-ui.field>
                    <x-ui.field label="New line name" for="a-nn" error="adjNewName" required class="col-span-2"><input id="a-nn" type="text" wire:model="adjNewName" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
                </div>
            @endif
        @endif

        <x-ui.field label="Amount (KES)" for="a-a" error="adjAmount" required><input id="a-a" type="text" inputmode="decimal" wire:model.live.debounce.400ms="adjAmount" class="{{ Ui::CONTROL }} font-numeric"></x-ui.field>
        <x-ui.field label="Reason" for="a-r" error="adjReason" required hint="For example the supplementary estimates, a Treasury circular or the CEO's instruction.">
            <textarea id="a-r" wire:model="adjReason" rows="3" maxlength="2000" class="{{ Ui::CONTROL }}"></textarea>
        </x-ui.field>

        @if ($shortfall)
            <div class="px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-red-700 text-[12px]" role="alert"><i class="fa-solid fa-triangle-exclamation mr-1"></i>This reduction would leave the department KES {{ Money::format($shortfall) }} short of what is already spent and committed this year.</div>
        @endif

        <x-slot:footer>
            <button type="button" wire:click="$set('showAdjustment', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="saveAdjustment" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="saveAdjustment">Save as draft</button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Submit, approve or discard --}}
    @if ($confirmItem)
        @php
            $isBudget = $confirmItem instanceof \App\Models\DepartmentBudget;
            [$cTitle, $cIcon, $cTone, $cButton] = match ($confirmAction) {
                'approve' => [$isBudget ? 'Approve this budget?' : 'Approve this adjustment?', 'fa-circle-check', 'emerald', 'Approve'],
                'submit' => [$isBudget ? 'Submit this budget to the CEO?' : 'Submit this adjustment to the CEO?', 'fa-paper-plane', 'emerald', 'Submit to the CEO'],
                default => [$isBudget ? 'Discard this draft budget?' : 'Discard this adjustment?', 'fa-trash-can', 'red', 'Discard'],
            };
        @endphp
        <x-ui.modal show="showConfirm" :open="$showConfirm" size="alert" :icon="$cIcon" :tone="$cTone" :title="$cTitle">
            <p class="text-[13px] text-slate-600">
                @if ($isBudget)
                    {{ $b->title() }}: {{ $lines->count() }} {{ Str::plural('line', $lines->count()) }}, KES {{ Money::format($totals['original']) }}.
                @else
                    {{ $confirmItem->summary() }}.
                @endif
            </p>
            <p class="text-[13px] text-slate-500">
                @switch ($confirmAction)
                    @case('approve') {{ $isBudget ? 'It becomes the department\'s allocation for the year, and its lines can be charged by memos. After this it changes only by adjustments.' : 'The budget changes now.' }} @break
                    @case('submit') It is locked until the CEO approves it or returns it for correction. The CEO is alerted. @break
                    @default It is kept in the record but no longer counts or appears in the list.
                @endswitch
            </p>
            <x-slot:footer>
                <button type="button" wire:click="$set('showConfirm', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
                <button type="button" wire:click="confirm" class="{{ $cTone === 'red' ? Ui::BTN_DANGER : Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="confirm">{{ $cButton }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- Return for correction --}}
    <x-ui.modal show="showReturn" :open="$showReturn" size="slim" icon="fa-reply" title="Return for correction" subtitle="It goes back to the preparer, who corrects it and resubmits it to you.">
        <x-ui.field label="What needs to be corrected" for="r-r" error="returnReason" required><textarea id="r-r" wire:model="returnReason" rows="4" maxlength="2000" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showReturn', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="sendBack" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="sendBack"><i class="fa-solid fa-reply text-xs"></i>Return for correction</button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.page>
