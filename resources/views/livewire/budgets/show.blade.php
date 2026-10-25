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
    $reviewing = $status === BudgetStatus::Submitted && $canApprove;
    $hasProposed = collect($amounts)->contains(fn ($a) => $a['proposed'] !== null && $a['proposed'] !== $a['original']);
    $lineComments = $reviews->whereNotNull('budget_line_id')->whereIn('event', [\App\Models\BudgetReview::COMMENT, \App\Models\BudgetReview::AMOUNT_CHANGED])->groupBy('budget_line_id');
    // The CEO approves a draft the CEO prepared directly; anyone else's draft is submitted first.
    $ceoOwnDraft = $canApprove && $status === BudgetStatus::Draft && $b->prepared_by === $me;
    $signed = fn (int $c) => $c === 0 ? '' : ($c > 0 ? '+' : '−').Money::format(abs($c));
@endphp

<x-ui.page icon="fa-wallet" :title="$b->department?->name.' workplan and budget · FY '.$b->financial_year" :subtitle="$approved ? 'Approved by the CEO: the department\'s budget for the year. Changes during the year are adjustments, each recorded with its reason.' : 'Counts for nothing until the CEO approves it.'">
    <x-slot:pills><x-ui.status :status="$status" /></x-slot:pills>
    <x-slot:actions>
        <a href="{{ route('budgets.index', ['fy' => $b->financial_year]) }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-arrow-left text-xs"></i>{{ $ownDepartment ? 'Workplan and budget' : 'All budgets' }}</a>
        @if ($editable)
            <button type="button" wire:click="openImport" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-file-import text-xs"></i>Import from template</button>
            <button type="button" wire:click="ask('discard', 'budget')" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-trash-can text-xs"></i>Discard draft</button>
            @if ($ceoOwnDraft)
                <button type="button" wire:click="ask('approve', 'budget')" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-circle-check text-xs"></i>Approve budget</button>
            @elseif (! $canApprove)
                <button type="button" wire:click="ask('submit', 'budget')" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-paper-plane text-xs"></i>{{ $status === BudgetStatus::Returned ? 'Resubmit to the CEO' : 'Submit to the CEO' }}</button>
            @endif
        @endif
        @if ($reviewing)
            <button type="button" wire:click="openComment" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-comment text-xs"></i>Add comment</button>
            <button type="button" wire:click="openReturn('budget')" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-reply text-xs"></i>Return for adjustment</button>
            <button type="button" wire:click="ask('approve', 'budget')" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-circle-check text-xs"></i>Approve budget</button>
        @endif
        @if ($approved && $canApprove)
            <button type="button" wire:click="openAdjustNow" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-sliders text-xs"></i>Adjust allocation</button>
        @elseif ($approved && $canPropose)
            <button type="button" wire:click="openAdjustment" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-plus text-xs"></i>Propose adjustment</button>
        @endif
    </x-slot:actions>

    @if ($status === BudgetStatus::Returned)
        <div class="px-4 py-3 rounded-lg bg-orange-50 border border-orange-200 text-orange-800 text-[13px]" role="alert">
            <p class="font-semibold"><i class="fa-solid fa-reply mr-1"></i>Returned for correction by {{ $b->returner?->name ?? 'the CEO' }} on {{ $b->returned_at?->format('d M Y') }}</p>
            <p class="mt-1 whitespace-pre-line">{{ $b->return_reason }}</p>
            @if ($canPrepare && ! $canApprove)<p class="mt-1 text-[12px]">Make the changes asked for (see the comments on the lines and the review history), then press <strong>Resubmit to the CEO</strong>.</p>@endif
        </div>
    @elseif ($status === BudgetStatus::Submitted && ! $canApprove)
        <div class="px-4 py-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-[13px]"><i class="fa-solid fa-hourglass-half mr-1"></i>With the CEO for review and approval since {{ $b->submitted_at?->format('d M Y') }}. It cannot be changed until the CEO approves it or returns it for adjustment.</div>
    @elseif ($reviewing)
        <div class="px-4 py-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-[13px]"><i class="fa-solid fa-magnifying-glass mr-1"></i>Submitted by {{ $b->submitter?->name ?? 'the department' }} on {{ $b->submitted_at?->format('d M Y') }}. Review each line: you can comment on it or change its amount (<i class="fa-solid fa-pen text-[10px]"></i>), then approve the budget or return it for adjustment.</div>
    @elseif ($canApprove && $status->isEditable() && ! $ceoOwnDraft)
        <div class="px-4 py-3 rounded-lg bg-slate-50 border border-slate-200 text-slate-600 text-[13px]"><i class="fa-solid fa-pen-ruler mr-1"></i>Being prepared by {{ $b->preparer?->name ?? 'the Office of the CEO' }}. It comes to you for approval when it is submitted.</div>
    @endif

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4 items-start">
        <div class="xl:col-span-2 flex flex-col gap-4">
            <x-ui.card title="Workplan activities and budget lines" icon="fa-list" :count="$lines->count()">
                @if ($editable)
                    <x-slot:actions><button type="button" wire:click="openLine" class="{{ Ui::BTN_TINT }}"><i class="fa-solid fa-plus text-[9px]"></i>Add line</button></x-slot:actions>
                @endif
                @if ($lines->isEmpty())
                    <x-ui.empty icon="fa-list" title="No lines yet" :line="$editable ? 'Add each workplan activity or vote with its estimated cost, or download the workplan template, fill it in and press Import from template.' : null" class="!py-8" />
                @else
                    @php $showActions = $editable || $reviewing; @endphp
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="border-b border-slate-100"><tr>
                                <th class="{{ Ui::TH }}">Activity or line</th><th class="{{ Ui::TH }}">Quarters</th>
                                <th class="{{ Ui::TH }} text-right">Qty × unit cost</th>
                                @if ($hasProposed)<th class="{{ Ui::TH }} text-right">Proposed</th>@endif
                                <th class="{{ Ui::TH }} text-right">{{ $approved ? 'As approved' : 'Allocation' }}</th>
                                @if ($approved)<th class="{{ Ui::TH }} text-right">Increases</th><th class="{{ Ui::TH }} text-right">Reductions</th><th class="{{ Ui::TH }} text-right">Allocation now</th>@endif
                                @if ($showActions)<th class="{{ Ui::TH }}"><span class="sr-only">Actions</span></th>@endif
                            </tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($lines as $line)
                                    @php $a = $amounts[$line->id]; @endphp
                                    <tr wire:key="l-{{ $line->id }}">
                                        <td class="{{ Ui::TD }} min-w-64">
                                            <p class="font-semibold text-slate-700">@if ($line->code)<span class="font-numeric font-normal text-slate-400">{{ $line->code }} · </span>@endif{{ $line->name }}</p>
                                            @if ($line->objective)<p class="text-[12px] text-slate-500"><span class="font-semibold">Objective:</span> {{ $line->objective }}</p>@endif
                                            @if ($line->deliverables)<p class="text-[12px] text-slate-500"><span class="font-semibold">Deliverables:</span> {{ $line->deliverables }}</p>@endif
                                            @if ($line->responsible_officer)<p class="text-[11px] text-slate-400"><i class="fa-solid fa-user text-[9px] mr-1"></i>{{ $line->responsible_officer }}</p>@endif
                                            @foreach ($lineComments->get($line->id, collect()) as $c)
                                                <p class="text-[12px] mt-1 px-2 py-1 rounded bg-amber-50 border border-amber-100 text-amber-800"><i class="fa-solid {{ $c->icon() }} text-[10px] mr-1"></i>{{ $c->user?->name ?? 'CEO' }}, {{ $c->created_at->format('d M') }}:@if ($c->event === \App\Models\BudgetReview::AMOUNT_CHANGED) KES {{ Money::format(Money::toCents($c->amount_before)) }} to {{ Money::format(Money::toCents($c->amount_after)) }}.@endif {{ $c->comment }}</p>
                                            @endforeach
                                        </td>
                                        <td class="{{ Ui::TD }} whitespace-nowrap font-numeric text-[12px]">{{ implode(' ', $line->quarterList()) ?: '' }}</td>
                                        <td class="{{ Ui::TD }} text-right whitespace-nowrap font-numeric text-[12px] text-slate-500">@if ($line->quantity !== null && $line->unit_cost !== null){{ rtrim(rtrim((string) $line->quantity, '0'), '.') }} × {{ Money::format(Money::toCents($line->unit_cost)) }}@endif</td>
                                        @if ($hasProposed)<td class="{{ Ui::TD }} text-right">@if ($a['proposed'] !== null)<x-ui.money :value="$a['proposed']" muted />@endif</td>@endif
                                        <td class="{{ Ui::TD }} text-right {{ $a['proposed'] !== null && $a['proposed'] !== $a['original'] ? 'font-semibold text-amber-700' : '' }}"><x-ui.money :value="$a['original']" /></td>
                                        @if ($approved)
                                            <td class="{{ Ui::TD }} text-right font-numeric text-emerald-700">{{ $a['increases'] ? '+'.Money::format($a['increases']) : '' }}</td>
                                            <td class="{{ Ui::TD }} text-right font-numeric text-red-600">{{ $a['decreases'] ? '−'.Money::format($a['decreases']) : '' }}</td>
                                            <td class="{{ Ui::TD }} text-right font-semibold"><x-ui.money :value="$a['current']" /></td>
                                        @endif
                                        @if ($showActions)
                                            <td class="{{ Ui::TD }} text-right whitespace-nowrap">
                                                @if ($editable)
                                                    <button type="button" wire:click="openLine({{ $line->id }})" class="{{ Ui::BTN_ICON }}" aria-label="Edit line"><i class="fa-solid fa-pen text-xs"></i></button>
                                                    <button type="button" wire:click="removeLine({{ $line->id }})" class="{{ Ui::BTN_ICON_DANGER }}" aria-label="Remove line"><i class="fa-solid fa-trash-can text-xs"></i></button>
                                                @else
                                                    <button type="button" wire:click="openRevise({{ $line->id }})" class="{{ Ui::BTN_ICON }}" aria-label="Change the amount of {{ $line->name }}"><i class="fa-solid fa-pen text-xs"></i></button>
                                                    <button type="button" wire:click="openComment({{ $line->id }})" class="{{ Ui::BTN_ICON }}" aria-label="Comment on {{ $line->name }}"><i class="fa-solid fa-comment text-xs"></i></button>
                                                @endif
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                                <tr class="bg-slate-50/60">
                                    <td class="{{ Ui::TD }} font-semibold text-slate-700" colspan="3">Total</td>
                                    @if ($hasProposed)<td class="{{ Ui::TD }} text-right"><x-ui.money :value="$totals['proposed']" muted /></td>@endif
                                    <td class="{{ Ui::TD }} text-right font-semibold"><x-ui.money :value="$totals['original']" /></td>
                                    @if ($approved)
                                        <td class="{{ Ui::TD }} text-right font-numeric font-semibold text-emerald-700">{{ $totals['increases'] ? '+'.Money::format($totals['increases']) : '' }}</td>
                                        <td class="{{ Ui::TD }} text-right font-numeric font-semibold text-red-600">{{ $totals['decreases'] ? '−'.Money::format($totals['decreases']) : '' }}</td>
                                        <td class="{{ Ui::TD }} text-right font-bold"><x-ui.money :value="$totals['current']" /></td>
                                    @endif
                                    @if ($showActions)<td class="{{ Ui::TD }}"></td>@endif
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
                                    $adjEditable = $adj->status->isEditable() && $canPropose;
                                    $adjOwnDraft = $canApprove && $adj->status === BudgetStatus::Draft && $adj->prepared_by === $me;
                                @endphp
                                <div class="px-5 py-3.5 flex flex-col sm:flex-row sm:items-start gap-3" wire:key="adj-{{ $adj->id }}">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-[13px] font-semibold text-slate-700">{{ $adj->kindLabel() }}</span>
                                            <x-ui.status :status="$adj->status" />
                                        </div>
                                        <p class="text-[13px] text-slate-600 mt-0.5">{{ $adj->summary() }}</p>
                                        @if ($adj->line_before !== null)
                                            <p class="text-[12px] text-slate-500 mt-0.5 font-numeric">{{ $adj->line?->label(withYear: false) }}: KES {{ Money::format(Money::toCents($adj->line_before)) }} → {{ Money::format(Money::toCents($adj->line_after)) }}@if ($adj->from_line_before !== null) · {{ $adj->fromLine?->label(withYear: false) }}: KES {{ Money::format(Money::toCents($adj->from_line_before)) }} → {{ Money::format(Money::toCents($adj->from_line_after)) }}@endif</p>
                                        @endif
                                        <p class="text-[12px] text-slate-500 mt-0.5"><span class="font-semibold">Reason:</span> {{ $adj->reason }}@if ($adj->effective_on) · <span class="font-semibold">Effective</span> {{ Ui::date($adj->effective_on) }}@endif</p>
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
            <x-ui.card title="Budget summary" icon="fa-calculator" padded>
                <dl class="space-y-2 text-[13px]">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Proposed by the department</dt><dd><x-ui.money :value="$totals['proposed']" /></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ $approved ? 'Approved by the CEO' : 'Allocation as it stands' }}</dt><dd class="font-semibold"><x-ui.money :value="$totals['original']" /></dd></div>
                    @if ($approved)
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Increases</dt><dd class="font-numeric text-emerald-700">+{{ Money::format($totals['increases']) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Reductions</dt><dd class="font-numeric text-red-600">−{{ Money::format($totals['decreases']) }}</dd></div>
                        <div class="flex justify-between gap-3 pt-2 border-t border-slate-100"><dt class="font-semibold text-slate-700">Current allocation</dt><dd class="font-bold"><x-ui.money :value="$totals['current']" /></dd></div>
                    @endif
                </dl>
                @if ($approved)<p class="text-[11px] text-slate-400 mt-3">Current allocation = approved + increases − reductions. A reallocation counts as an increase on one line and a reduction on the other. The approved figures never change.</p>@endif
            </x-ui.card>

            <x-ui.card title="Workplan" icon="fa-bullseye" padded>
                <dl class="space-y-3 text-[13px]">
                    <div>
                        <dt class="{{ Ui::MICRO }} flex items-center justify-between">Objectives for the year @if ($editable)<button type="button" wire:click="openNotes" class="text-emerald-700 normal-case tracking-normal text-[11px] font-semibold hover:underline">Edit</button>@endif</dt>
                        <dd class="text-slate-600 whitespace-pre-line">{{ $b->objectives ?: 'Not given' }}</dd>
                    </div>
                    <div>
                        <dt class="{{ Ui::MICRO }}">Workplan document</dt>
                        <dd class="text-slate-600">
                            @if ($b->hasWorkplanFile())
                                <a href="{{ route('budgets.workplan', $b) }}" class="text-emerald-700 font-semibold hover:underline"><i class="fa-solid fa-paperclip text-xs mr-1"></i>{{ $b->workplan_file_name }}</a>
                            @else
                                None attached.
                            @endif
                            @if ($editable)
                                <label class="block mt-2 {{ Ui::BTN_TINT }} w-max cursor-pointer"><i class="fa-solid fa-upload text-[9px]"></i><span wire:loading.remove wire:target="workplanFile">{{ $b->hasWorkplanFile() ? 'Replace' : 'Attach' }} (PDF, Word or Excel)</span><span wire:loading wire:target="workplanFile">Uploading…</span><input type="file" wire:model="workplanFile" class="sr-only" accept=".pdf,.doc,.docx,.xls,.xlsx"></label>
                                @error('workplanFile')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                                <p class="text-[11px] text-slate-400 mt-1">The document supports the lines above; the CEO reviews and approves the lines.</p>
                            @endif
                        </dd>
                    </div>
                </dl>
            </x-ui.card>

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
                        <dt class="{{ Ui::MICRO }}">Notes</dt>
                        <dd class="text-slate-600 whitespace-pre-line">{{ $b->notes ?: 'None' }}</dd>
                    </div>
                </dl>
                <p class="text-[11px] text-slate-400 mt-3">Every change is kept in the audit trail.</p>
            </x-ui.card>

            <x-ui.card title="Review history" icon="fa-clock-rotate-left" :count="$reviews->count()">
                @if ($reviews->isEmpty())
                    <x-ui.empty icon="fa-clock-rotate-left" title="Not submitted yet" line="Each submission, comment, change of amount, return and approval appears here." class="!py-6" />
                @else
                    <ol class="divide-y divide-slate-100">
                        @foreach ($reviews as $r)
                            <li class="px-5 py-3" wire:key="rv-{{ $r->id }}">
                                <p class="text-[13px] font-semibold text-slate-700"><i class="fa-solid {{ $r->icon() }} text-[11px] text-slate-400 mr-1"></i>{{ $r->label() }}@if ($r->line) <span class="font-normal text-slate-500">· {{ $r->line->name }}</span>@endif</p>
                                @if ($r->amount_before !== null)<p class="text-[12px] font-numeric text-slate-600">KES {{ Money::format(Money::toCents($r->amount_before)) }} → {{ Money::format(Money::toCents($r->amount_after)) }}</p>@endif
                                @if ($r->snapshot)<p class="text-[12px] text-slate-500">{{ count($r->snapshot['lines'] ?? []) }} lines, KES {{ Money::format(Money::toCents($r->snapshot['total'] ?? '0')) }}</p>@endif
                                @if ($r->comment)<p class="text-[12px] text-slate-600 whitespace-pre-line mt-0.5">{{ $r->comment }}</p>@endif
                                <p class="text-[11px] text-slate-400 mt-0.5">{{ $r->user?->name ?? 'Unknown' }} · {{ $r->created_at->format('d M Y H:i') }}</p>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-ui.card>
        </div>
    </div>

    {{-- Budget line --}}
    <x-ui.modal show="showLine" :open="$showLine" icon="fa-list" :title="$lineId ? 'Edit workplan line' : 'Add a workplan line'" subtitle="A workplan activity or vote, what it delivers and its estimated cost for the year.">
        <div class="grid grid-cols-3 gap-3">
            <x-ui.field label="Vote code" for="l-c" error="lineCode"><input id="l-c" type="text" wire:model="lineCode" maxlength="40" class="{{ Ui::CONTROL }} font-numeric" placeholder="2210300"></x-ui.field>
            <x-ui.field label="Activity or budget line" for="l-n" error="lineName" required class="col-span-2"><input id="l-n" type="text" wire:model="lineName" maxlength="255" class="{{ Ui::CONTROL }}" placeholder="e.g. Network support visits to constituency offices"></x-ui.field>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <x-ui.field label="Objective" for="l-o" error="lineObjective"><textarea id="l-o" wire:model="lineObjective" rows="2" maxlength="2000" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
            <x-ui.field label="Deliverables" for="l-d" error="lineDeliverables"><textarea id="l-d" wire:model="lineDeliverables" rows="2" maxlength="2000" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        </div>
        <div class="grid grid-cols-3 gap-3">
            <x-ui.field label="Quantity" for="l-q" error="lineQuantity"><input id="l-q" type="text" inputmode="decimal" wire:model="lineQuantity" class="{{ Ui::CONTROL }} font-numeric" placeholder="24"></x-ui.field>
            <x-ui.field label="Unit cost (KES)" for="l-u" error="lineUnitCost"><input id="l-u" type="text" inputmode="decimal" wire:model="lineUnitCost" class="{{ Ui::CONTROL }} font-numeric" placeholder="35,000.00"></x-ui.field>
            <x-ui.field label="Amount (KES)" for="l-a" error="lineAmount" hint="Leave empty to use quantity × unit cost."><input id="l-a" type="text" inputmode="decimal" wire:model="lineAmount" class="{{ Ui::CONTROL }} font-numeric"></x-ui.field>
        </div>
        <x-ui.field label="Quarters" for="l-qt" error="lineQuarters">
            <div class="flex flex-wrap gap-3">
                @foreach (\App\Models\BudgetLine::QUARTERS as $q => $label)
                    <label class="inline-flex items-center gap-1.5 text-[13px] text-slate-600"><input type="checkbox" value="{{ $q }}" wire:model="lineQuarters" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">{{ $label }}</label>
                @endforeach
            </div>
        </x-ui.field>
        <x-ui.field label="Responsible officer" for="l-r" error="lineResponsible"><input id="l-r" type="text" wire:model="lineResponsible" maxlength="255" class="{{ Ui::CONTROL }}" placeholder="Name or title"></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showLine', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="saveLine" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="saveLine">Save line</button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Notes --}}
    <x-ui.modal show="showNotes" :open="$showNotes" size="slim" icon="fa-bullseye" title="Workplan objectives and notes">
        <x-ui.field label="Objectives for the year" for="n-o" error="objectives" hint="What the department sets out to achieve. Each line above is an activity towards it."><textarea id="n-o" wire:model="objectives" rows="4" maxlength="5000" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        <x-ui.field label="Notes" for="n-n" error="notes"><textarea id="n-n" wire:model="notes" rows="3" maxlength="2000" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showNotes', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="saveNotes" class="{{ Ui::BTN_PRIMARY }}">Save</button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Adjustment --}}
    <x-ui.modal show="showAdjustment" :open="$showAdjustment" icon="fa-sliders" :title="$adjustDirect ? 'Adjust the approved allocation' : ($adjustmentId ? 'Edit adjustment' : 'Propose a budget adjustment')" :subtitle="$adjustDirect ? 'Applied as soon as you confirm, with your reason and the effective date. The approved budget is kept; the change is recorded beside it.' : 'Saved as a draft. It changes the budget only once the CEO approves it.'">
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
        <x-ui.field label="Effective date" for="a-e" error="adjEffective" required><input id="a-e" type="date" wire:model="adjEffective" class="{{ Ui::CONTROL }}"></x-ui.field>
        <x-ui.field label="Reason" for="a-r" error="adjReason" required hint="For example the supplementary estimates, a Treasury circular or the CEO's instruction.">
            <textarea id="a-r" wire:model="adjReason" rows="3" maxlength="2000" class="{{ Ui::CONTROL }}"></textarea>
        </x-ui.field>

        @if ($preview)
            <div class="px-3 py-2.5 rounded-lg bg-amber-50 border border-amber-200 text-amber-900 text-[13px] space-y-1" role="alert">
                <p class="font-semibold"><i class="fa-solid fa-circle-question mr-1"></i>Apply this adjustment?</p>
                @if ($preview['from'])<p class="font-numeric">Line taken from: KES {{ Money::format($preview['from'][0]) }} → {{ Money::format($preview['from'][1]) }}</p>@endif
                <p class="font-numeric">{{ $adjLine === '' ? 'New line' : 'Line' }}: KES {{ Money::format($preview['to'][0]) }} → {{ Money::format($preview['to'][1]) }}</p>
                <p class="font-numeric">Department allocation now: KES {{ Money::format($totals['current']) }} → {{ Money::format($totals['current'] + $preview['total']) }}</p>
            </div>
        @endif

        @if ($shortfall)
            <div class="px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-red-700 text-[12px]" role="alert"><i class="fa-solid fa-triangle-exclamation mr-1"></i>This reduction would leave the department KES {{ Money::format($shortfall) }} short of what is already spent and committed this year.</div>
        @endif

        <x-slot:footer>
            <button type="button" wire:click="$set('showAdjustment', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            @if (! $adjustDirect)
                <button type="button" wire:click="saveAdjustment" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="saveAdjustment">Save as draft</button>
            @elseif ($adjConfirming)
                <button type="button" wire:click="applyAdjustment" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="applyAdjustment"><i class="fa-solid fa-check text-xs"></i>Apply adjustment</button>
            @else
                <button type="button" wire:click="checkAdjustment" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="checkAdjustment">Check adjustment</button>
            @endif
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
    <x-ui.modal show="showReturn" :open="$showReturn" size="slim" icon="fa-reply" title="Return for adjustment" subtitle="It goes back to the preparer, who changes it and resubmits it to you. This submission and its comments stay in the history.">
        <x-ui.field label="What needs to change, and why" for="r-r" error="returnReason" required><textarea id="r-r" wire:model="returnReason" rows="4" maxlength="2000" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showReturn', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="sendBack" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="sendBack"><i class="fa-solid fa-reply text-xs"></i>Return for adjustment</button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Import the workplan template --}}
    <x-ui.modal show="showImport" :open="$showImport" size="slim" icon="fa-file-import" title="Import the workplan" subtitle="Fill in the workplan template (one row per activity), then upload it. Rows are added to the lines; a row with the same code and name updates that line. The file is kept as the workplan document.">
        <a href="{{ route('budgets.template') }}" class="{{ Ui::BTN_TINT }} w-max"><i class="fa-solid fa-file-excel text-[9px]"></i>Download the workplan template</a>
        <x-ui.field label="Filled-in workplan (.xlsx or .csv)" for="i-f" error="importFile" required><input id="i-f" type="file" wire:model="importFile" accept=".xlsx,.xls,.csv" class="{{ Ui::CONTROL }}"></x-ui.field>
        @if ($importErrors !== [])
            <div class="px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-red-700 text-[12px]" role="alert">
                <p class="font-semibold">Nothing was imported. Fix these rows:</p>
                <ul class="list-disc ml-4 mt-1">@foreach ($importErrors as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif
        <x-slot:footer>
            <button type="button" wire:click="$set('showImport', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="import" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="import,importFile"><i class="fa-solid fa-file-import text-xs"></i>Import</button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- The CEO changes a line's amount before approving --}}
    <x-ui.modal show="showRevise" :open="$showRevise" size="slim" icon="fa-pen-to-square" title="Change the amount" subtitle="The department's proposed amount is kept; your amount becomes the allocation if you approve the budget.">
        <x-ui.field label="Allocation (KES)" for="v-a" error="reviseAmount" required><input id="v-a" type="text" inputmode="decimal" wire:model="reviseAmount" class="{{ Ui::CONTROL }} font-numeric"></x-ui.field>
        <x-ui.field label="Reason (shown to the department)" for="v-c" error="reviseComment"><textarea id="v-c" wire:model="reviseComment" rows="3" maxlength="2000" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showRevise', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="revise" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="revise">Save amount</button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- A comment on the submission or a line --}}
    <x-ui.modal show="showComment" :open="$showComment" size="slim" icon="fa-comment" :title="$commentLineId ? 'Comment on this line' : 'Comment on the workplan'" subtitle="Kept in the review history and shown to the department.">
        <x-ui.field label="Comment" for="c-t" error="commentText" required><textarea id="c-t" wire:model="commentText" rows="4" maxlength="2000" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showComment', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="saveComment" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="saveComment">Add comment</button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.page>
