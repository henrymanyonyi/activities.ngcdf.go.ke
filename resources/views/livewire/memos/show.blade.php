@use('App\Support\Ui')
@use('App\Support\Money')
@use('App\Enums\ActivityStatus')
@php
    $s = $a->status;
    $returned = $s === ActivityStatus::ReturnedToHod;
    $hasMemo = $a->documents->contains('kind', \App\Models\ActivityDocument::KIND_MEMO);
    $blocking = collect($issues)->where('blocking', true);
@endphp

<x-ui.page icon="fa-envelope-open-text" :title="$a->title" :subtitle="$a->reference.' · '.($a->organisingDepartment?->name ?? 'No department').' · '.Ui::dateRange($a->start_date, $a->end_date)">
    <x-slot:pills><x-ui.status :status="$s" /></x-slot:pills>
    <x-slot:actions>
        <a href="{{ route('memos.index') }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-arrow-left text-xs"></i>Memos</a>
        <a href="{{ route('memos.document', $a) }}" target="_blank" rel="noopener" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-file-pdf text-xs text-red-600"></i>Preview memo</a>
        @if ($editable)
            @if ($canDiscard)
                <button type="button" wire:click="$set('showDiscard', true)" class="{{ Ui::BTN_NEUTRAL }} hover:!text-red-600"><i class="fa-solid fa-trash text-xs"></i>Discard draft</button>
            @endif
            <a href="{{ route('memos.edit', $a) }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-pen text-xs"></i>Edit memo</a>
            <a href="{{ route('memos.team', $a) }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-users text-xs"></i>Team and costs</a>
            @if ($withHod)
                <button type="button" wire:click="openHodAction('showDecline')" class="{{ Ui::BTN_NEUTRAL }} hover:!text-red-600"><i class="fa-solid fa-circle-xmark text-xs"></i>Decline</button>
                <button type="button" wire:click="openHodAction('showReturnToOfficer')" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-reply text-xs"></i>Return to the officer</button>
                <button type="button" wire:click="openHodAction('showForward')" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-share text-xs"></i>Forward</button>
            @elseif ($s !== ActivityStatus::WithHod)
                <button type="button" wire:click="openSubmit" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-paper-plane text-xs"></i>{{ $returned ? 'Resubmit for review' : 'Submit for review' }}</button>
            @endif
        @endif
    </x-slot:actions>

    @if ($a->isDelegated())
        <div class="px-4 py-3 rounded-lg text-sm bg-violet-50 border border-violet-200 text-violet-800">
            <i class="fa-solid fa-user-check mr-2"></i>Prepared by <strong>{{ $a->submitted_by_name }}</strong>@if ($a->preparedBy?->designation_id) ({{ $a->preparedBy->designation?->name }})@endif at the request of {{ $a->responsibleHodLabel() ?? 'the Head of Department' }}.
            @if ($a->isWithPreparer())
                {{ $s === ActivityStatus::ReturnedToPreparer ? 'Returned to them for changes.' : 'They are still preparing it.' }} It comes to you for review when they submit it; you cannot change it meanwhile.
            @elseif ($s === ActivityStatus::WithHod)
                Review it (you can also edit it), then forward it to the Chief of Staff, return it to the officer with comments, or decline it.
            @endif
        </div>
    @endif

    @if ($a->isWithPreparer() || $s === ActivityStatus::WithHod)
    @elseif ($editable && ! $returned)
        <div class="px-4 py-3 rounded-lg text-sm bg-sky-50 border border-sky-200 text-sky-800"><i class="fa-solid fa-circle-info mr-2"></i>This memo is a draft. Only your department can see it until you press <strong>Submit for review</strong>.</div>
    @elseif ($returned)
        <div class="px-4 py-3 rounded-lg text-sm bg-orange-50 border border-orange-200 text-orange-800"><i class="fa-solid fa-reply mr-2"></i>Returned to you for correction. Make the changes asked for below, then press <strong>Resubmit for review</strong>.</div>
    @elseif ($s->isApprovedOrLater() || $s === ActivityStatus::Declined || $s === ActivityStatus::Cancelled)
        <div class="px-4 py-3 rounded-lg text-sm bg-slate-100 border border-slate-200 text-slate-600"><i class="fa-solid fa-lock mr-2"></i>{{ $a->wasDeclinedByHod() ? 'You declined this memo'.($a->status_reason ? ': '.$a->status_reason : '.').' It is kept for reference.' : 'The CEO has decided on this memo. The decision and its history are below.' }}</div>
    @else
        <div class="px-4 py-3 rounded-lg text-sm bg-slate-100 border border-slate-200 text-slate-600"><i class="fa-solid fa-lock mr-2"></i>This memo is with the {{ $a->currentHolder() }}. You can follow it here; it can be changed again only if it is returned to you.</div>
    @endif

    @if ($editable)
        <x-ui.card :title="$blocking->isEmpty() ? 'Ready to submit' : 'Before you submit'" icon="{{ $blocking->isEmpty() ? 'fa-circle-check' : 'fa-list-check' }}" padded>
            <p class="text-[13px] text-slate-600 mb-3">The system writes the memo for you from what you enter, with Annex A (budget breakdown), Annex B (detailed budget) and Annex C (participant itinerary). Press <strong>Preview memo</strong> to read it as the reviewer and the CEO will.</p>
            @if ($issues === [])
                <p class="text-[13px] text-emerald-700"><i class="fa-solid fa-circle-check mr-1"></i>Nothing is missing.</p>
            @else
                <ul class="space-y-1.5">
                    @foreach ($issues as $issue)
                        <li class="text-[13px] {{ $issue['blocking'] ? 'text-red-700' : 'text-amber-700' }}"><i class="fa-solid {{ $issue['blocking'] ? 'fa-circle-xmark' : 'fa-triangle-exclamation' }} mr-1.5"></i>{{ $issue['message'] }}@if ($issue['blocking'])<span class="text-[11px] font-semibold uppercase ml-1">Needed</span>@endif</li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    @endif

    @if (in_array($s, [ActivityStatus::InProgress, ActivityStatus::Completed, ActivityStatus::ReportReceived, ActivityStatus::Closed], true))
        @php
            $report = $a->report;
            $planned = $a->costs->sum(fn ($c) => Money::toCents($c->estimated_amount));
            $spent = $a->costs->whereNotNull('actual_amount')->sum(fn ($c) => Money::toCents($c->actual_amount));
            $canReport = $s === ActivityStatus::Completed && (! $report || $report->isReturned());
        @endphp
        <x-ui.card title="Activity report" icon="fa-file-circle-check" padded>
            <x-slot:actions>
                @php $t = $a->reportTimeliness(); @endphp
                @if ($t)
                    <x-ui.badge :classes="match ($t['status']) { 'on_time' => 'bg-emerald-50 text-emerald-700', 'due' => 'bg-amber-50 text-amber-700', default => 'bg-red-50 text-red-700' }" icon="fa-clock">{{ $a->reportTimelinessLabel() }}</x-ui.badge>
                @endif
                @if ($canReport)
                    <button type="button" wire:click="openReport" class="{{ Ui::BTN_TINT }}"><i class="fa-solid fa-file-circle-check text-[9px]"></i>{{ $report ? 'Correct and resubmit the report' : 'Submit activity report' }}</button>
                @endif
            </x-slot:actions>

            @if ($s === ActivityStatus::InProgress)
                <p class="text-[13px] text-slate-600"><i class="fa-solid fa-person-walking-luggage mr-1 text-blue-600"></i>Under way. Once it ends on {{ Ui::date($a->end_date) }} you submit the activity report here, with what was actually spent.</p>
            @elseif ($report?->isReturned())
                <div class="px-3 py-2 rounded-lg bg-orange-50 border border-orange-200 text-orange-800 text-[13px] mb-3"><p class="font-semibold"><i class="fa-solid fa-reply mr-1"></i>Returned for correction by {{ $report->reviewer?->name ?? 'the Office of the CEO' }} on {{ $report->reviewed_at?->format('d M Y') }}</p><p class="mt-1 whitespace-pre-line">{{ $report->return_reason }}</p></div>
            @elseif ($report?->isSubmitted())
                <p class="text-[13px] text-amber-700 mb-3"><i class="fa-solid fa-hourglass-half mr-1"></i>Submitted on {{ $report->submitted_at?->format('d M Y') }}. Waiting for the Office of the CEO to accept it.</p>
            @elseif ($report)
                <p class="text-[13px] text-emerald-700 mb-3"><i class="fa-solid fa-circle-check mr-1"></i>Accepted{{ $report->reviewer ? ' by '.$report->reviewer->name : '' }}{{ $report->reviewed_at ? ' on '.$report->reviewed_at->format('d M Y') : '' }}.</p>
            @else
                <p class="text-[13px] text-slate-600"><i class="fa-solid fa-flag-checkered mr-1 text-indigo-600"></i>The activity is complete. Submit the activity report{{ $a->report_due_on ? ' by '.Ui::date($a->report_due_on) : '' }}: what it achieved and what was actually spent on each cost line.</p>
            @endif

            @if ($report)
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-[13px]">
                    <div class="sm:col-span-3"><dt class="{{ Ui::MICRO }}">What it achieved</dt><dd class="text-slate-700 whitespace-pre-line">{{ $report->outputs_achieved }}</dd></div>
                    <div><dt class="{{ Ui::MICRO }}">Planned</dt><dd><x-ui.money :value="$planned" /></dd></div>
                    <div><dt class="{{ Ui::MICRO }}">Actually spent</dt><dd class="font-semibold"><x-ui.money :value="$spent" /></dd></div>
                    <div><dt class="{{ Ui::MICRO }}">Difference</dt><dd class="font-numeric {{ $spent > $planned ? 'text-red-600' : 'text-emerald-700' }}">{{ $spent > $planned ? 'over by' : 'under by' }} {{ Money::format(abs($planned - $spent)) }}</dd></div>
                    @if ($report->document)<div class="sm:col-span-3"><a href="{{ route('documents.show', $report->document) }}" class="text-[13px] font-semibold text-emerald-700 hover:underline"><i class="fa-solid fa-paperclip mr-1"></i>{{ $report->document->original_name }}</a></div>@endif
                </dl>
            @endif
        </x-ui.card>
    @endif

    <x-memo.workflow :activity="$a" />

    <x-ui.card title="Team and costs" icon="fa-users" padded>
        <div class="flex flex-col sm:flex-row sm:items-center gap-4">
            <div class="grid grid-cols-3 gap-3 flex-1">
                <div><p class="{{ Ui::MICRO }}">Officers</p><p class="text-lg font-bold text-slate-800 font-numeric">{{ $a->staff_count }}</p></div>
                <div><p class="{{ Ui::MICRO }}">External</p><p class="text-lg font-bold text-slate-800 font-numeric">{{ $a->external_count }}</p></div>
                <div><p class="{{ Ui::MICRO }}">Planned cost</p><p class="text-lg font-bold text-slate-800 font-numeric"><span class="text-[10px] text-slate-400 mr-0.5">KES</span>{{ Money::format(Money::toCents($a->estimated_total)) }}</p></div>
            </div>
            <a href="{{ route('memos.team', $a) }}" wire:navigate class="{{ $editable ? Ui::BTN_PRIMARY : Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-users text-xs"></i>{{ $editable ? 'Build the team and costs' : 'View the team and costs' }}</a>
        </div>
        @if ($budget)
            <x-memo.budget-position :position="$budget" class="mt-5 pt-5 border-t border-slate-100" />
        @endif
        @if ($editable && $a->staff_count === 0)
            <p class="text-[12px] text-amber-700 mt-3"><i class="fa-solid fa-circle-info mr-1"></i>Add the officers taking part. The CEO needs at least one officer on the team.</p>
        @endif
    </x-ui.card>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <x-ui.card title="The memo" icon="fa-clipboard-list" class="lg:col-span-2" padded>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div><dt class="{{ Ui::MICRO }}">Category · type</dt><dd class="text-sm text-slate-700">{{ $a->category?->name }}{{ $a->type ? ' · '.$a->type->name : '' }}</dd></div>
                <div><dt class="{{ Ui::MICRO }}">Dates</dt><dd class="text-sm text-slate-700 font-numeric">{{ Ui::dateRange($a->start_date, $a->end_date) }} · {{ $a->days }} {{ Str::plural('day', $a->days) }}</dd></div>
                <div class="sm:col-span-2"><dt class="{{ Ui::MICRO }}">Purpose</dt><dd class="text-sm text-slate-700 whitespace-pre-line">{{ $a->purpose }}</dd></div>
                <div class="sm:col-span-2"><dt class="{{ Ui::MICRO }}">Justification</dt><dd class="text-sm whitespace-pre-line {{ $a->justification ? 'text-slate-700' : 'text-slate-400 italic' }}">{{ $a->justification ?: 'Not given' }}</dd></div>
                <div class="sm:col-span-2"><dt class="{{ Ui::MICRO }}">Expected outcomes and deliverables</dt><dd class="text-sm whitespace-pre-line {{ $a->expected_outputs ? 'text-slate-700' : 'text-slate-400 italic' }}">{{ $a->expected_outputs ?: 'Not given' }}</dd></div>
                <div class="sm:col-span-2"><dt class="{{ Ui::MICRO }}">Participants and stakeholders</dt><dd class="text-sm {{ $a->target_audience ? 'text-slate-700' : 'text-slate-400 italic' }}">{{ $a->target_audience ?: 'Not given' }}</dd></div>
                <div class="sm:col-span-2"><dt class="{{ Ui::MICRO }}">Venue and location</dt>
                    <dd class="text-sm text-slate-700">
                        @forelse ($a->locations as $loc)
                            <span class="inline-flex items-center gap-2 mr-3" wire:key="loc-{{ $loc->id }}"><i class="fa-solid fa-location-dot text-emerald-600"></i>{{ $loc->label() }}
                                @if ($editable)<button type="button" wire:click="removeLocation({{ $loc->id }})" class="{{ Ui::BTN_ICON_DANGER }}" aria-label="Remove location"><i class="fa-solid fa-xmark text-xs"></i></button>@endif
                            </span>
                        @empty
                            <span class="text-slate-400 italic">Not given.</span>
                        @endforelse
                        @if ($editable)<button type="button" wire:click="$set('showLocation', true)" class="{{ Ui::BTN_TINT }} mt-1"><i class="fa-solid fa-plus text-[9px]"></i>Add location</button>@endif
                    </dd>
                </div>
                <div><dt class="{{ Ui::MICRO }}">Estimated budget (department)</dt><dd class="text-sm text-slate-700 font-numeric">{{ $a->requested_budget !== null ? 'KES '.Money::format(Money::toCents($a->requested_budget)) : 'Not given' }}</dd></div>
                <div><dt class="{{ Ui::MICRO }}">Budget line</dt><dd class="text-sm text-slate-700">{{ $a->budgetLine?->label() ?? 'Not given' }}</dd></div>
                @if ($a->notes)<div class="sm:col-span-2"><dt class="{{ Ui::MICRO }}">Notes</dt><dd class="text-sm text-slate-700 whitespace-pre-line">{{ $a->notes }}</dd></div>@endif
            </dl>
        </x-ui.card>

        <x-ui.card title="Documents" icon="fa-paperclip" :count="$a->documents->count()" padded>
            <div class="space-y-1.5">
                @forelse ($a->documents as $d)
                    <a href="{{ route('documents.show', $d) }}" class="flex items-center gap-2 px-3 py-2 border border-slate-100 rounded-lg hover:bg-slate-50 text-[13px] text-slate-700" wire:key="doc-{{ $d->id }}">
                        <i class="fa-solid fa-file text-emerald-600"></i><span class="truncate flex-1">{{ $d->original_name }}</span>
                        <span class="text-[10px] text-slate-400 uppercase">{{ $d->kind === 'memo' ? 'Signed memo' : 'Supporting' }}</span>
                    </a>
                @empty
                    <p class="text-[13px] text-slate-400">No documents yet.</p>
                @endforelse
            </div>
            @if ($editable)
                <form wire:submit="uploadDocument" class="mt-4 space-y-2">
                    <x-ui.field :label="$hasMemo ? 'Add a supporting document' : 'Attach a signed copy or supporting document'" for="doc" error="document" hint="Optional: the system generates the memo. For example the invitation letter. PDF, Word, Excel or image, up to 10 MB.">
                        <input id="doc" type="file" wire:model="document" class="block w-full text-[13px] text-slate-600 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:font-semibold">
                        <div wire:loading wire:target="document" class="text-[11px] text-slate-400 mt-1">Uploading…</div>
                    </x-ui.field>
                    <button type="submit" class="{{ Ui::BTN_TINT }}" wire:loading.attr="disabled" wire:target="document,uploadDocument"><i class="fa-solid fa-paperclip text-[9px]"></i>Attach</button>
                </form>
            @endif
        </x-ui.card>
    </div>

    <x-ui.modal show="showSubmit" :open="$showSubmit" size="slim" icon="fa-paper-plane" :title="$returned ? 'Resubmit for review?' : 'Submit for review?'" :subtitle="$a->reference.' · '.$a->title">
        @if ($returned)
            <p class="text-[13px] text-slate-600">It goes back to the <strong>{{ $a->review_office?->label() ?? 'reviewer' }}</strong>, who returned it. You cannot change it while it is being reviewed.</p>
        @else
            <x-ui.field label="Send it to" for="s-office" error="office" required>
                <select id="s-office" wire:model.live="office" class="{{ Ui::CONTROL }} appearance-none pr-8">
                    @foreach ($offices as $o)<option value="{{ $o->value }}">{{ $o->label() }}</option>@endforeach
                </select>
            </x-ui.field>
            <p class="text-[13px] text-slate-600">The reviewer forwards it to the CEO for decision. You cannot change the memo while it is being reviewed; if something needs correcting, the reviewer will return it to you.</p>
        @endif
        <x-memo.signing-capacity :designation="$designation" />
        @if ($blocking->isNotEmpty())
            <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-[13px] text-red-800" role="alert">
                <p class="font-semibold mb-1">Complete these first:</p>
                <ul class="list-disc pl-5 space-y-0.5">@foreach ($blocking as $issue)<li>{{ $issue['message'] }}</li>@endforeach</ul>
            </div>
        @else
            <p class="text-[13px] text-slate-600"><a href="{{ route('memos.document', $a) }}" target="_blank" rel="noopener" class="font-semibold text-emerald-700 hover:underline">Preview the memo and annexes</a> once more before you send it.</p>
        @endif
        <x-ui.field :label="$returned ? 'What you changed (optional)' : 'Note to the reviewer (optional)'" for="s-note" error="note">
            <textarea id="s-note" wire:model="note" rows="3" class="{{ Ui::CONTROL }}" maxlength="2000"></textarea>
        </x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showSubmit', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit" class="{{ Ui::BTN_PRIMARY }}" @disabled($blocking->isNotEmpty())><i class="fa-solid fa-paper-plane text-xs"></i><span wire:loading.remove wire:target="submit">{{ $returned ? 'Resubmit for review' : 'Submit for review' }}</span><span wire:loading wire:target="submit">Sending…</span></button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showLocation" :open="$showLocation" size="slim" icon="fa-location-dot" title="Add a location" subtitle="Choose the county so allowances (DSA) can be calculated, and the venue.">
        <x-ui.field label="Region" for="l-r" error="locRegion"><select id="l-r" wire:model="locRegion" class="{{ Ui::CONTROL }} appearance-none pr-8"><option value="">Any</option>@foreach ($regions as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach</select></x-ui.field>
        <x-ui.field label="County" for="l-c" error="locCounty"><select id="l-c" wire:model.live="locCounty" class="{{ Ui::CONTROL }} appearance-none pr-8"><option value="">Choose…</option>@foreach ($counties as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-ui.field>
        <x-ui.field label="Constituency" for="l-k" error="locConstituency"><select id="l-k" wire:model.live="locConstituency" class="{{ Ui::CONTROL }} appearance-none pr-8" @disabled($constituencies->isEmpty())><option value="">{{ $locCounty ? 'Any' : 'Choose a county first' }}</option>@foreach ($constituencies as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-ui.field>
        <x-ui.field label="Venue" for="l-v" error="locVenue"><input id="l-v" type="text" wire:model="locVenue" maxlength="255" class="{{ Ui::CONTROL }}" placeholder="e.g. NGCDF Board headquarters"></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showLocation', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="addLocation" wire:loading.attr="disabled" wire:target="addLocation" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-plus text-xs"></i>Add location</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showDiscard" :open="$showDiscard" size="alert" tone="red" icon="fa-trash" title="Discard this draft?" subtitle="It is removed from your list. Nothing has been sent to anyone.">
        <x-slot:footer>
            <button type="button" wire:click="$set('showDiscard', false)" class="{{ Ui::BTN_NEUTRAL }}">Keep it</button>
            <button type="button" wire:click="discard" wire:loading.attr="disabled" wire:target="discard" class="{{ Ui::BTN_DANGER }}"><i class="fa-solid fa-trash text-xs"></i>Discard draft</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showReport" :open="$showReport" size="large" icon="fa-file-circle-check" title="Activity report" subtitle="What the activity achieved and what was actually spent. The amounts count against your budget as soon as you submit; the Office of the CEO then accepts the report or returns it for correction.">
        <x-ui.field label="What the activity achieved" for="r-o" error="outputsAchieved" required :hint="$a->expected_outputs ? 'Expected: '.Str::limit($a->expected_outputs, 200) : null">
            <textarea id="r-o" wire:model="outputsAchieved" rows="4" maxlength="5000" class="{{ Ui::CONTROL }}"></textarea>
        </x-ui.field>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <x-ui.field label="Key findings" for="r-f" error="findings"><textarea id="r-f" wire:model="findings" rows="3" maxlength="5000" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
            <x-ui.field label="Recommendations" for="r-r" error="recommendations"><textarea id="r-r" wire:model="recommendations" rows="3" maxlength="5000" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        </div>

        <div>
            <p class="{{ Ui::LABEL }}">Actually spent on each cost line <span class="text-red-500">*</span></p>
            <div class="border border-slate-200 rounded-xl overflow-x-auto">
                <table class="w-full">
                    <thead class="border-b border-slate-100 bg-slate-50/60"><tr><th class="{{ Ui::TH }}">Cost</th><th class="{{ Ui::TH }}">For</th><th class="{{ Ui::TH }} text-right">Planned</th><th class="{{ Ui::TH }} text-right w-48">Actually spent (KES)</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($a->costs as $c)
                            <tr wire:key="act-{{ $c->id }}">
                                <td class="{{ Ui::TD }}">{{ $c->category?->name }}<p class="text-[11px] text-slate-400">{{ $c->description }}</p></td>
                                <td class="{{ Ui::TD }}">{{ $c->participant?->displayName() ?? 'Whole activity' }}</td>
                                <td class="{{ Ui::TD }} text-right"><x-ui.money :value="$c->estimated_amount" /></td>
                                <td class="{{ Ui::TD }} text-right">
                                    <input type="text" inputmode="decimal" wire:model="actuals.{{ $c->id }}" class="{{ Ui::CONTROL }} font-numeric text-right" aria-label="Actually spent on {{ $c->category?->name }}">
                                    @error('actuals.'.$c->id)<p class="text-xs text-red-600 mt-1 text-left">{{ $message }}</p>@enderror
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="{{ Ui::TD }} text-slate-400">This activity has no cost lines.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="text-[11px] text-slate-400 mt-1">Enter 0 where nothing was spent. Use the figures from the payments made, such as the imprest surrender.</p>
        </div>

        <x-ui.field label="Report document" for="r-d" error="reportFile" hint="Optional. PDF or Word: the signed back-to-office report.">
            <input id="r-d" type="file" wire:model="reportFile" accept=".pdf,.doc,.docx" class="block w-full text-[13px] text-slate-600 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:font-semibold">
        </x-ui.field>

        <x-slot:footer>
            <button type="button" wire:click="$set('showReport', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="submitReport" wire:loading.attr="disabled" wire:target="submitReport,reportFile" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-paper-plane text-xs"></i>Submit report</button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- A memo an officer prepared, with the Head for review --}}
    <x-ui.modal show="showForward" :open="$showForward" size="slim" icon="fa-share" title="Forward this memo for review?" subtitle="On the officer's behalf. It is recorded that you reviewed and forwarded it; the reviewer forwards it to the CEO for decision.">
        <x-ui.field label="Send it to" for="hf-t" error="forwardTo" required>
            <select id="hf-t" wire:model.live="forwardTo" class="{{ Ui::CONTROL }} appearance-none pr-8">@foreach ($offices as $o)<option value="{{ $o->value }}">{{ $o->label() }}</option>@endforeach</select>
        </x-ui.field>
        <x-memo.signing-capacity :designation="$designation" />
        <x-ui.field label="Note (optional)" for="hf-n" error="note"><textarea id="hf-n" wire:model="note" rows="3" maxlength="2000" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showForward', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="forwardForReview" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="forwardForReview"><i class="fa-solid fa-share text-xs"></i>Forward</button>
        </x-slot:footer>
    </x-ui.modal>
    <x-ui.modal show="showReturnToOfficer" :open="$showReturnToOfficer" size="slim" icon="fa-reply" title="Return to the officer" subtitle="They get a new link by email and SMS, see your comments on the memo page, and submit it to you again.">
        <x-ui.field label="What they must change" for="hr-r" error="hodReason" required><textarea id="hr-r" wire:model="hodReason" rows="4" maxlength="2000" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showReturnToOfficer', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="returnToOfficer" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="returnToOfficer"><i class="fa-solid fa-reply text-xs"></i>Return to the officer</button>
        </x-slot:footer>
    </x-ui.modal>
    <x-ui.modal show="showDecline" :open="$showDecline" size="slim" icon="fa-circle-xmark" tone="red" title="Decline this memo?" subtitle="It is kept, read only, with your reason. It does not go to the Office of the CEO. The officer is told it was declined.">
        <x-ui.field label="Reason" for="hd-r" error="hodReason" required><textarea id="hd-r" wire:model="hodReason" rows="3" maxlength="2000" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showDecline', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="declineMemo" class="{{ Ui::BTN_DANGER }}" wire:loading.attr="disabled" wire:target="declineMemo">Decline</button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.page>
