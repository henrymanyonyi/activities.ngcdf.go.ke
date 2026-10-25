@use('App\Support\Ui')
@use('App\Support\Money')
@use('App\Enums\ActivityStatus')
@use('App\Enums\DecisionType')
@use('App\Enums\ParticipantRole')
@use('App\Enums\ParticipationStatus')
@use('App\Enums\ExternalCategory')
@use('App\Enums\TravelMode')
@use('App\Enums\ImprestStatus')
@use('App\Models\ActivityAmendment')
@php
    $a = $activity;
    $s = $a->status;
    $sel = Ui::CONTROL.' appearance-none pr-8';
    $can = fn (string $p) => $user->can($p);
    $editable = ! $s->isReadOnly() && $can('activities.manage');
    $amend = $s->requiresAmendment();
    $tabs = [
        'overview' => ['icon' => 'fa-circle-info', 'label' => 'Overview', 'count' => 0],
        'team' => ['icon' => 'fa-users', 'label' => 'Team', 'count' => $a->participants->count()],
        'costs' => ['icon' => 'fa-coins', 'label' => 'Costs', 'count' => $a->costs->count()],
        'decisions' => ['icon' => 'fa-gavel', 'label' => 'Decisions & directives', 'count' => $a->directives->where('status', 'open')->count()],
        'report' => ['icon' => 'fa-file-circle-check', 'label' => 'Report', 'count' => 0],
        'history' => ['icon' => 'fa-clock-rotate-left', 'label' => 'History', 'count' => 0],
    ];
    $cell = 'rounded-lg border border-slate-200 bg-white px-3 py-2.5';
@endphp

<x-ui.page icon="fa-clipboard-list" :title="$a->title" :subtitle="$a->reference.' · '.($a->organisingDepartment?->name ?? 'No requesting department').' · '.Ui::dateRange($a->start_date, $a->end_date)" statsKey="activityStats">
    <x-slot:pills>
        <x-ui.status :status="$s" />
        <x-ui.rag :rag="$a->rag()" />
        @if ($a->is_late_notice)<x-ui.badge classes="bg-amber-50 text-amber-700" icon="fa-clock">Late notice</x-ui.badge>@endif
        @if ($a->is_retrospective)<x-ui.badge classes="bg-red-50 text-red-700" icon="fa-backward">Retrospective</x-ui.badge>@endif
        @if ($a->submission_link_id)<x-ui.badge classes="bg-blue-50 text-blue-700" icon="fa-link">Received via link</x-ui.badge>@endif
    </x-slot:pills>

    <x-slot:actions>
        <a href="{{ route('activities.index') }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-arrow-left text-xs"></i>Register</a>
        <a href="{{ route('memos.document', $a) }}" target="_blank" rel="noopener" class="{{ Ui::BTN_NEUTRAL }}" title="The memo with Annex A budget breakdown, Annex B detailed budget and Annex C itinerary"><i class="fa-solid fa-file-pdf text-xs text-red-600"></i>Memo and annexes</a>

        @if ($editable)
            <a href="{{ route('activities.edit', $a) }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-pen text-xs"></i>Edit</a>
        @endif
        @if ($s === ActivityStatus::Draft && $can('activities.manage'))
            <button type="button" wire:click="$set('showDiscard', true)" class="{{ Ui::BTN_NEUTRAL }} hover:!text-red-600"><i class="fa-solid fa-trash text-xs"></i>Discard</button>
        @endif
        @if (in_array($s, [ActivityStatus::UnderReview, ActivityStatus::Returned]) && $can('memos.review') && ! $withChiefOfStaffOnly)
            <button type="button" wire:click="$set('showReturnHod', true)" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-reply text-xs"></i>Return for correction</button>
        @endif
        @if (in_array($s, [ActivityStatus::Draft, ActivityStatus::UnderReview, ActivityStatus::Returned]) && $can('activities.submit') && ! $a->isDepartmentDraft() && ! $withChiefOfStaffOnly)
            <button type="button" wire:click="$set('showForward', true)" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-paper-plane text-xs"></i>{{ $forwardsToChiefOfStaff ? 'Forward to Chief of Staff' : ($can('activities.decide') ? 'Submit for decision' : 'Forward to CEO') }}</button>
        @endif
        @if ($s === ActivityStatus::AwaitingDecision && $can('activities.decide'))
            <button type="button" wire:click="openDecide('returned')" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-rotate-left text-xs"></i>Return</button>
            <button type="button" wire:click="openDecide('declined')" class="{{ Ui::BTN_DANGER }}"><i class="fa-solid fa-xmark text-xs"></i>Decline</button>
            <button type="button" wire:click="openDecide('approved')" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-check text-xs"></i>Approve and authorise</button>
        @endif
        @if (in_array($s, [ActivityStatus::Draft, ActivityStatus::AwaitingDecision, ActivityStatus::Returned]) && $can('decisions.record') && ! $can('activities.decide'))
            <button type="button" wire:click="$set('showRecordDecision', true)" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-file-signature text-xs"></i>Record CEO decision</button>
        @endif
        @if ($s === ActivityStatus::Approved && $can('activities.execute') && ! $a->start_date->isFuture())
            <button type="button" wire:click="confirmCommencement" wire:loading.attr="disabled" wire:target="confirmCommencement" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-person-walking-luggage text-xs"></i>Confirm commencement</button>
        @endif
        @if (in_array($s, [ActivityStatus::Approved, ActivityStatus::Postponed, ActivityStatus::AwaitingDecision]) && $can('activities.postpone'))
            <button type="button" wire:click="$set('showPostpone', true)" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-calendar-xmark text-xs"></i>{{ $s === ActivityStatus::Postponed ? 'Reschedule' : 'Postpone' }}</button>
        @endif
        @if (in_array($s, [ActivityStatus::Approved, ActivityStatus::InProgress]) && $can('activities.execute'))
            <button type="button" wire:click="$set('showExtend', true)" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-calendar-plus text-xs"></i>Extend</button>
        @endif
        @if ($s === ActivityStatus::InProgress && $can('activities.execute'))
            <button type="button" wire:click="$set('showComplete', true)" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-flag-checkered text-xs"></i>Mark completed</button>
        @endif
        @if ($s === ActivityStatus::Completed && $can('activities.execute'))
            @if ($a->report?->isSubmitted())
                <button type="button" wire:click="$set('showReturnReport', true)" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-reply text-xs"></i>Return report</button>
                <button type="button" wire:click="acceptReport" wire:loading.attr="disabled" wire:target="acceptReport" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-file-circle-check text-xs"></i>Accept report</button>
            @elseif (! $a->report)
                <button type="button" wire:click="$set('showReport', true)" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-file-circle-check text-xs"></i>Record report</button>
            @endif
        @endif
        @if ($s === ActivityStatus::ReportReceived && $can('activities.close'))
            <button type="button" wire:click="$set('showClose', true)" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-lock text-xs"></i>Close</button>
        @endif
        @if (in_array($s, [ActivityStatus::Approved, ActivityStatus::InProgress, ActivityStatus::Postponed]) && $can('activities.cancel'))
            <button type="button" wire:click="$set('showCancel', true)" class="{{ Ui::BTN_NEUTRAL }} hover:!text-red-600"><i class="fa-solid fa-ban text-xs"></i>Cancel</button>
        @endif
    </x-slot:actions>

    <x-slot:stats>
        <x-ui.stat :index="0" icon="fa-users" label="Officers" :value="$a->staff_count" :sub="$a->external_count ? '+'.$a->external_count.' external' : 'staff participants'" />
        <x-ui.stat :index="1" icon="fa-calendar-days" label="Days · nights" :value="$a->days.' · '.$a->nights" :sub="Ui::dateRange($a->start_date, $a->end_date)" tone="blue" />
        <x-ui.stat :index="2" icon="fa-coins" label="Planned cost" :value="Money::format(Money::toCents($a->estimated_total))" money :sub="$a->approved_estimated_total ? 'approved at KES '.Money::format(Money::toCents($a->approved_estimated_total)) : null" tone="amber" />
        <x-ui.stat :index="3" icon="fa-receipt" label="Actual cost" :value="Money::format(Money::toCents($a->actual_total))" money :sub="$breakdown['staff_per_head'] !== null ? 'KES '.Money::format($breakdown['staff_per_head']).' per officer' : null" />
    </x-slot:stats>

    @if ($s->isReadOnly())
        <div class="px-4 py-3 rounded-lg text-sm bg-slate-100 border border-slate-200 text-slate-600"><i class="fa-solid fa-lock mr-2"></i>This activity is {{ strtolower($s->label()) }} and read only.@if ($a->status_reason) Reason: {{ $a->status_reason }}@endif</div>
    @endif
    @if ($a->isDepartmentDraft())
        <div class="px-4 py-3 rounded-lg text-sm bg-sky-50 border border-sky-200 text-sky-800"><i class="fa-solid fa-circle-info mr-2"></i>The Head of Department is still preparing this memo. It reaches you when they submit it for review.</div>
    @endif
    @if ($s->isPendingDecision() || $s->isWithDepartment() || $a->reviewNotes->isNotEmpty())
        <x-memo.workflow :activity="$a">
            @if (($s->isPendingDecision() || $s === ActivityStatus::ReturnedToHod) && $can('memos.review'))
                <x-slot:actions><button type="button" wire:click="$set('showNote', true)" class="{{ Ui::BTN_TINT }}"><i class="fa-solid fa-comment text-[9px]"></i>Add comment</button></x-slot:actions>
            @endif
        </x-memo.workflow>
    @endif
    @if ($a->commencementRag() && in_array($a->commencementRag()->value, ['amber', 'red']))
        <div class="px-4 py-3 rounded-lg text-sm {{ $a->commencementRag()->badgeClasses() }} border border-current/20"><i class="fa-solid fa-triangle-exclamation mr-2"></i>The start date has passed and commencement has not been confirmed.</div>
    @endif
    @if ($a->isReportOverdue())
        <div class="px-4 py-3 rounded-lg text-sm bg-red-50 border border-red-200 text-red-700"><i class="fa-solid fa-file-circle-exclamation mr-2"></i>The back-to-office report was due {{ Ui::date($a->report_due_on) }}. Follow up through existing channels.</div>
    @endif

    <section class="{{ Ui::CARD }}">
        {{-- Secondary tabs on the card (PAKA-RANGI §4.8). --}}
        <div role="tablist" class="flex items-stretch gap-0.5 px-3 pt-2 bg-slate-50/60 border-b border-slate-200 overflow-x-auto">
            @foreach ($tabs as $key => $cfg)
                @php $on = $tab === $key; @endphp
                <button type="button" role="tab" aria-selected="{{ $on ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')"
                    class="group inline-flex items-center gap-2 -mb-px px-4 py-2.5 text-[13px] font-semibold whitespace-nowrap rounded-t-lg border-b-2 transition {{ $on ? 'bg-white text-emerald-700 border-emerald-600' : 'text-slate-500 border-transparent hover:text-slate-800 hover:bg-white/70' }}">
                    <i class="fa-solid {{ $cfg['icon'] }} text-[10px] {{ $on ? 'text-emerald-600' : 'text-slate-400' }}"></i>{{ $cfg['label'] }}
                    @if ($cfg['count'] > 0)<span class="font-numeric px-1.5 py-0.5 rounded-full text-[10px] font-bold {{ $on ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">{{ $cfg['count'] }}</span>@endif
                </button>
            @endforeach
        </div>

        <div class="p-5">
            {{-- ── Overview ─────────────────────────────────────────── --}}
            @if ($tab === 'overview')
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                    <div class="lg:col-span-2 space-y-4">
                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div><dt class="{{ Ui::MICRO }}">Category · type</dt><dd class="text-sm text-slate-700">{{ $a->category?->name }}{{ $a->type ? ' · '.$a->type->name : '' }}</dd></div>
                            <div><dt class="{{ Ui::MICRO }}">Source memo</dt><dd class="text-sm text-slate-700 font-numeric">{{ $a->source_reference ?: '—' }}{{ $a->source_received_on ? ', received '.Ui::date($a->source_received_on) : '' }}</dd></div>
                            <div class="sm:col-span-2"><dt class="{{ Ui::MICRO }}">Objective</dt><dd class="text-sm text-slate-700 whitespace-pre-line">{{ $a->purpose }}</dd></div>
                            @if ($a->expected_outputs)<div class="sm:col-span-2"><dt class="{{ Ui::MICRO }}">Expected outputs</dt><dd class="text-sm text-slate-700 whitespace-pre-line">{{ $a->expected_outputs }}</dd></div>@endif
                            @if ($a->justification)<div class="sm:col-span-2"><dt class="{{ Ui::MICRO }}">Justification</dt><dd class="text-sm text-slate-700 whitespace-pre-line">{{ $a->justification }}</dd></div>@endif
                            @if ($a->target_audience)<div class="sm:col-span-2"><dt class="{{ Ui::MICRO }}">Participants and stakeholders</dt><dd class="text-sm text-slate-700">{{ $a->target_audience }}</dd></div>@endif
                            @if ($a->responsibleHodLabel())<div><dt class="{{ Ui::MICRO }}">Head of Department</dt><dd class="text-sm text-slate-700">{{ $a->responsibleHodLabel() }}@if ($a->isPreparedOnBehalf())<span class="text-[11px] text-purple-700"> · prepared on their behalf by {{ $a->creator?->name }}</span>@endif</dd></div>@endif
                            @if ($a->requested_budget !== null)<div><dt class="{{ Ui::MICRO }}">Department's estimate</dt><dd class="text-sm text-slate-700 font-numeric">KES {{ Money::format(Money::toCents($a->requested_budget)) }}</dd></div>@endif
                            @if ($a->notes)<div class="sm:col-span-2"><dt class="{{ Ui::MICRO }}">Notes</dt><dd class="text-sm text-slate-700 whitespace-pre-line">{{ $a->notes }}</dd></div>@endif
                            <div><dt class="{{ Ui::MICRO }}">Budget line</dt><dd class="text-sm text-slate-700">{{ $a->budgetLine?->label() ?? '—' }}</dd></div>
                            <div><dt class="{{ Ui::MICRO }}">Approval</dt><dd class="text-sm text-slate-700">{{ $a->approval_mode ? $a->approval_mode->label().($a->approval_reference ? ' ('.$a->approval_reference.')' : '').', '.Ui::date($a->approved_at) : 'Not yet approved' }}</dd></div>
                            @if ($a->commenced_at)<div><dt class="{{ Ui::MICRO }}">Actual dates</dt><dd class="text-sm text-slate-700 font-numeric">{{ Ui::dateRange($a->actual_start_date, $a->actual_end_date) }}</dd></div>@endif
                            @if ($a->submission_link_id)<div><dt class="{{ Ui::MICRO }}">Submitted by</dt><dd class="text-sm text-slate-700">{{ $a->submitted_by_name }}{{ $a->submitted_by_email ? ' · '.$a->submitted_by_email : '' }}, {{ Ui::date($a->submitted_at) }}</dd></div>@endif
                        </dl>

                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <h3 class="{{ Ui::MICRO }}">Locations</h3>
                                @if ($editable)<button type="button" wire:click="$set('showLocation', true)" class="{{ Ui::BTN_TINT }}"><i class="fa-solid fa-plus text-[9px]"></i>Add location</button>@endif
                            </div>
                            @forelse ($a->locations as $loc)
                                <div class="flex items-center justify-between gap-3 px-3 py-2 border border-slate-100 rounded-lg mb-1.5" wire:key="loc-{{ $loc->id }}">
                                    <span class="text-sm text-slate-700"><i class="fa-solid fa-location-dot text-emerald-600 mr-2"></i>{{ $loc->label() }}</span>
                                    @if ($editable)<button type="button" wire:click="removeLocation({{ $loc->id }})" wire:loading.attr="disabled" class="{{ Ui::BTN_ICON_DANGER }}" aria-label="Remove location"><i class="fa-solid fa-xmark text-xs"></i></button>@endif
                                </div>
                            @empty
                                <p class="text-sm text-slate-400">No location yet. A location is required before submitting to the CEO.</p>
                            @endforelse
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="{{ Ui::MICRO }}">Documents</h3>
                            @if ($editable)<button type="button" wire:click="$set('showDocument', true)" class="{{ Ui::BTN_TINT }}"><i class="fa-solid fa-paperclip text-[9px]"></i>Attach</button>@endif
                        </div>
                        @forelse ($a->documents as $doc)
                            <a href="{{ route('documents.show', $doc) }}" class="flex items-center gap-3 px-3 py-2 border border-slate-100 rounded-lg mb-1.5 hover:bg-slate-50" wire:key="doc-{{ $doc->id }}">
                                <i class="fa-solid fa-file-lines text-slate-400"></i>
                                <span class="min-w-0"><span class="block text-[13px] text-slate-700 truncate">{{ $doc->original_name }}</span><span class="block text-[11px] text-slate-400">{{ $doc->kindLabel() }} · {{ Ui::date($doc->created_at) }}</span></span>
                            </a>
                        @empty
                            <p class="text-sm text-slate-400">{{ $a->isDepartmentOriginated() ? 'None attached. The memo is generated by the system: see Memo and annexes.' : 'No documents. Attach the memo, concept note or terms of reference.' }}</p>
                        @endforelse

                        <x-memo.budget-position :position="$budget" class="mt-5" />
                    </div>
                </div>
            @endif

            {{-- ── Team ─────────────────────────────────────────────── --}}
            @if ($tab === 'team')
                <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                    <p class="text-[13px] text-slate-500">Field days shown for this quarter and financial year, including this activity, against the limits in Settings.</p>
                    @if ($editable && ! $s->isDelivered())
                        <div class="flex gap-2">
                            <button type="button" wire:click="$set('showAddExternal', true)" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-user-tie text-xs"></i>Add external</button>
                            <button type="button" wire:click="$set('showAddStaff', true)" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-user-plus text-xs"></i>Add officers</button>
                        </div>
                    @endif
                </div>

                @if ($a->participants->isEmpty())
                    <x-ui.empty icon="fa-users" title="No team yet" line="Add the officers named in the memo. Participants are chosen from the staff list." />
                @else
                    <div class="overflow-x-auto -mx-5">
                        <table class="w-full">
                            <thead class="border-b border-slate-100"><tr>
                                <th class="{{ Ui::TH }}">Participant</th><th class="{{ Ui::TH }}">Role</th><th class="{{ Ui::TH }}">Field days (Q · FY)</th>
                                <th class="{{ Ui::TH }}">Attendance</th><th class="{{ Ui::TH }} text-right">{{ $s->isDelivered() ? 'Days attended' : 'Days' }}</th><th class="{{ Ui::TH }} text-right">Cost incl. share</th><th class="{{ Ui::TH }}"></th>
                            </tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($breakdown['rows'] as $row)
                                    @php $p = $row['participant']; $fd = $fieldDays[$p->id] ?? null; @endphp
                                    <tr wire:key="p-{{ $p->id }}" class="hover:bg-slate-50/60">
                                        <td class="{{ Ui::TD }}">
                                            <p class="font-medium text-slate-700">{{ $p->displayName() }}@if ($p->is_external)<x-ui.badge class="ml-1" classes="bg-slate-100 text-slate-500">External</x-ui.badge>@endif</p>
                                            <p class="text-[11px] text-slate-400">
                                                @if ($p->is_external){{ $p->external_category?->label() }}{{ $p->external_organisation ? ' · '.$p->external_organisation : '' }}
                                                @else{{ $p->staff?->staff_number }} · {{ $p->department?->name ?? $p->staff?->department?->name }}{{ $p->job_grade ? ' · '.$p->job_grade : '' }}@endif
                                            </p>
                                            @foreach ($p->releases as $rel)
                                                <p class="text-[11px] text-purple-700 mt-0.5" wire:key="rel-{{ $rel->id }}"><i class="fa-solid fa-person-walking-arrow-loop-left mr-1"></i>{{ $rel->kindLabel() }}: last day {{ $rel->last_day->format('d M Y') }} (was {{ $rel->original_end_date->format('d M Y') }}){{ $rel->destination ? ', to '.$rel->destination->reference : '' }}. Asked by {{ $rel->initiated_by_name }}; recorded by {{ $rel->recorder?->name }}.</p>
                                            @endforeach
                                            @if (isset($conflicts[$p->id]))
                                                <p class="text-[11px] text-red-600 mt-0.5">
                                                    <i class="fa-solid fa-triangle-exclamation mr-1"></i>Also on {{ $conflicts[$p->id]->pluck('reference')->implode(', ') }}
                                                    @if ($p->conflict_reason)
                                                        <span class="text-slate-500"> · Reason: {{ $p->conflict_reason }}</span>
                                                    @else
                                                        <span class="font-semibold"> · no reason given</span>
                                                        @if ($editable)
                                                            <button type="button" wire:click="openExplain({{ $p->id }})" class="ml-1.5 font-semibold text-emerald-700 hover:underline">Give reason</button>
                                                        @endif
                                                    @endif
                                                </p>
                                            @endif
                                        </td>
                                        <td class="{{ Ui::TD }}">{{ $p->role->label() }}</td>
                                        <td class="{{ Ui::TD }} font-numeric whitespace-nowrap">
                                            @if ($fd)
                                                <span class="{{ $fd['over_quarter'] ? 'text-red-600 font-semibold' : '' }}">{{ $fd['quarter'] }}/{{ $fd['quarter_limit'] }}</span> ·
                                                <span class="{{ $fd['over_year'] ? 'text-red-600 font-semibold' : '' }}">{{ $fd['year'] }}/{{ $fd['year_limit'] }}</span>
                                                @if ($fd['over_quarter'] || $fd['over_year'])<i class="fa-solid fa-flag text-red-500 text-[10px] ml-1" title="Over the field-day limit"></i>@endif
                                            @else — @endif
                                        </td>
                                        <td class="{{ Ui::TD }}">
                                            @if (in_array($s, [ActivityStatus::InProgress, ActivityStatus::Completed, ActivityStatus::ReportReceived]) && $can('activities.execute'))
                                                <select wire:change="setAttendance({{ $p->id }}, $event.target.value)" class="{{ $sel }} !py-1 text-[13px]" aria-label="Attendance for {{ $p->displayName() }}">
                                                    @foreach (ParticipationStatus::cases() as $ps)<option value="{{ $ps->value }}" @selected($p->status === $ps)>{{ $ps->label() }}</option>@endforeach
                                                </select>
                                            @else
                                                <x-ui.badge :classes="$p->status->badgeClasses()">{{ $p->status->label() }}</x-ui.badge>
                                            @endif
                                        </td>
                                        <td class="{{ Ui::TD }} text-right font-numeric whitespace-nowrap">
                                            @if (! $s->isDelivered())
                                                <div class="inline-flex items-start justify-end gap-1">
                                                    <div>
                                                        <span class="{{ $p->isPartial($a) ? 'text-slate-800 font-semibold' : '' }}">{{ $p->plannedDays($a) }}</span>
                                                        @if ($p->isPartial($a))<p class="text-[11px] text-slate-400">{{ $p->startOn($a)->format('d M') }} – {{ $p->endOn($a)->format('d M') }}</p>@endif
                                                    </div>
                                                    @if ($editable)
                                                        <button type="button" wire:click="openDays({{ $p->id }})" class="{{ Ui::BTN_ICON }} -mt-1" title="Change {{ $p->displayName() }}'s days" aria-label="Change {{ $p->displayName() }}'s days"><i class="fa-solid fa-calendar-day text-xs"></i></button>
                                                    @endif
                                                </div>
                                            @elseif ($p->status === ParticipationStatus::Attended && $can('activities.execute') && ! $s->isReadOnly())
                                                <input type="number" min="0" max="366" value="{{ $p->days_attended ?? $p->plannedDays($a) }}" wire:change="setDaysAttended({{ $p->id }}, $event.target.value)" class="{{ Ui::CONTROL }} !py-1 w-20 text-right" aria-label="Days attended">
                                            @else
                                                {{ $p->days_attended ?? $p->plannedDays($a) }}
                                            @endif
                                        </td>
                                        <td class="{{ Ui::TD }} text-right">
                                            <x-ui.money :value="$row['total']" />
                                            @if ($row['share'])<p class="text-[11px] text-slate-400 font-numeric">incl. {{ Money::format($row['share']) }} shared</p>@endif
                                        </td>
                                        <td class="{{ Ui::TD }} text-right whitespace-nowrap">
                                            @if ($canRelease && ! $p->is_external && $p->status->isExpected() && $p->plannedDays($a) > 1)
                                                <button type="button" wire:click="openRelease({{ $p->id }})" class="{{ Ui::BTN_ICON }}" title="Recall, release or reassign {{ $p->displayName() }}" aria-label="Recall, release or reassign {{ $p->displayName() }}"><i class="fa-solid fa-person-walking-arrow-loop-left text-xs"></i></button>
                                            @endif
                                            @if ($editable && ! $s->isDelivered())
                                                <button type="button" wire:click="confirmRemoveParticipant({{ $p->id }})" class="{{ Ui::BTN_ICON_DANGER }}" aria-label="Remove {{ $p->displayName() }}"><i class="fa-solid fa-user-minus text-xs"></i></button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif

            {{-- ── Costs ────────────────────────────────────────────── --}}
            @if ($tab === 'costs')
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 mb-4">
                    <div class="{{ $cell }}"><p class="{{ Ui::MICRO }}">Planned</p><p class="text-[13px] font-bold font-numeric">{{ Money::format(Money::toCents($a->estimated_total)) }}</p></div>
                    <div class="{{ $cell }}"><p class="{{ Ui::MICRO }}">Actual</p><p class="text-[13px] font-bold font-numeric">{{ Money::format(Money::toCents($a->actual_total)) }}</p></div>
                    <div class="{{ $cell }} {{ $a->actuals_complete ? 'border-emerald-200 bg-emerald-50' : '' }}"><p class="{{ Ui::MICRO }}">Per officer</p><p class="text-[13px] font-bold font-numeric">{{ $breakdown['staff_per_head'] !== null ? Money::format($breakdown['staff_per_head']) : '—' }}</p></div>
                    <div class="{{ $cell }}"><p class="{{ Ui::MICRO }}">Per head incl. external</p><p class="text-[13px] font-bold font-numeric">{{ $breakdown['all_per_head'] !== null ? Money::format($breakdown['all_per_head']) : '—' }}</p></div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                    <p class="text-[13px] text-slate-500">DSA is computed from the rate table (one standard rate per scale, per {{ app(\App\Services\AppSettings::class)->get('dsa_basis') === 'days' ? 'day' : 'night' }}). Travel and other costs are entered per participant or for the whole activity.</p>
                    <div class="flex gap-2">
                        @if ($editable && ! $s->isDelivered())
                            <button type="button" wire:click="recalculateDsa" wire:loading.attr="disabled" wire:target="recalculateDsa" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-calculator text-xs"></i>Recalculate DSA</button>
                            <button type="button" wire:click="openCost" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-plus text-xs"></i>Add cost</button>
                        @endif
                        @if ($can('activities.execute') && ! $s->isReadOnly())
                            <button type="button" wire:click="$set('showImprest', true)" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-receipt text-xs"></i>Imprest</button>
                        @endif
                    </div>
                </div>

                @if ($a->imprest_reference || $a->imprest_status)
                    <p class="text-[13px] text-slate-600 mb-3"><i class="fa-solid fa-receipt text-slate-400 mr-1"></i>Imprest {{ $a->imprest_reference ?: '(no reference)' }} · {{ $a->imprest_status?->label() ?? 'status not set' }}</p>
                @endif

                @if ($a->costs->isEmpty())
                    <x-ui.empty icon="fa-coins" title="No costs yet" line="DSA appears once the team, a location in a county and the DSA rates are in place." />
                @else
                    @php $canActual = $can('activities.execute') && ($s->isDelivered() || $s === ActivityStatus::InProgress) && ! $s->isReadOnly(); @endphp
                    <div class="overflow-x-auto -mx-5">
                        <table class="w-full">
                            <thead class="border-b border-slate-100"><tr>
                                <th class="{{ Ui::TH }}">Cost</th><th class="{{ Ui::TH }}">For</th><th class="{{ Ui::TH }} text-right">Planned</th>
                                <th class="{{ Ui::TH }} text-right">Actual</th><th class="{{ Ui::TH }}">Finance ref.</th><th class="{{ Ui::TH }}"></th>
                            </tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($a->costs->sortBy(fn ($c) => ($c->category?->sort_order ?? 99).'-'.$c->id) as $line)
                                    <tr wire:key="cost-{{ $line->id }}" class="hover:bg-slate-50/60">
                                        <td class="{{ Ui::TD }}">
                                            <p class="font-medium text-slate-700">{{ $line->category?->name }}@if ($line->travel_mode) <span class="text-[11px] text-slate-400">· {{ $line->travel_mode->label() }}</span>@endif</p>
                                            @if ($line->description)<p class="text-[11px] text-slate-400">{{ $line->description }}</p>@endif
                                            @if ($line->isOverridden())<p class="text-[11px] text-amber-700"><i class="fa-solid fa-pen-to-square mr-1"></i>Overridden from {{ Money::format(Money::toCents($line->computed_amount)) }}: {{ $line->override_reason }}</p>@endif
                                        </td>
                                        <td class="{{ Ui::TD }}">{{ $line->participant?->displayName() ?? 'Whole activity (shared)' }}</td>
                                        <td class="{{ Ui::TD }} text-right"><x-ui.money :value="$line->estimated_amount" /></td>
                                        <td class="{{ Ui::TD }} text-right">
                                            @if ($canActual)
                                                <input type="text" inputmode="decimal" wire:model="actuals.{{ $line->id }}" wire:blur="saveActual({{ $line->id }})" placeholder="0.00" class="{{ Ui::CONTROL }} !py-1 w-32 text-right font-numeric" aria-label="Actual amount">
                                                @error("actuals.{$line->id}")<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                                            @else
                                                @if ($line->actual_amount !== null)<x-ui.money :value="$line->actual_amount" />@else<span class="text-slate-300">—</span>@endif
                                            @endif
                                        </td>
                                        <td class="{{ Ui::TD }}">
                                            @if ($canActual)
                                                <input type="text" wire:model="financeRefs.{{ $line->id }}" wire:blur="saveActual({{ $line->id }})" maxlength="100" class="{{ Ui::CONTROL }} !py-1 w-32" aria-label="Finance reference">
                                            @else {{ $line->finance_reference ?: '—' }} @endif
                                        </td>
                                        <td class="{{ Ui::TD }} text-right whitespace-nowrap">
                                            @if ($editable && ! $s->isDelivered())
                                                @if ($line->is_computed)
                                                    @can('reference.manage')<button type="button" wire:click="openOverride({{ $line->id }})" class="{{ Ui::BTN_ICON }}" title="Override computed DSA"><i class="fa-solid fa-pen-to-square text-xs"></i></button>@endcan
                                                @else
                                                    <button type="button" wire:click="openCost({{ $line->id }})" class="{{ Ui::BTN_ICON }}" aria-label="Edit"><i class="fa-solid fa-pen text-xs"></i></button>
                                                    @unless ($amend)<button type="button" wire:click="removeCost({{ $line->id }})" class="{{ Ui::BTN_ICON_DANGER }}" aria-label="Remove"><i class="fa-solid fa-trash text-xs"></i></button>@endunless
                                                @endif
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="border-t border-slate-200 bg-slate-50">
                                @foreach ($byCategory as $c)
                                    <tr><td class="px-4 py-1.5 text-[11px] text-slate-500" colspan="2">{{ $c['category'] }}</td><td class="px-4 py-1.5 text-right text-[11px] font-numeric">{{ Money::format($c['planned']) }}</td><td class="px-4 py-1.5 text-right text-[11px] font-numeric">{{ Money::format($c['actual']) }}</td><td colspan="2"></td></tr>
                                @endforeach
                            </tfoot>
                        </table>
                    </div>
                @endif
            @endif

            {{-- ── Decisions & directives ─────────────────────────── --}}
            @if ($tab === 'decisions')
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                    <div>
                        <h3 class="{{ Ui::MICRO }} mb-2">CEO decisions</h3>
                        @forelse ($a->decisions->reverse() as $d)
                            <div class="border border-slate-100 rounded-xl p-4 mb-2" wire:key="dec-{{ $d->id }}">
                                <div class="flex items-center justify-between gap-2">
                                    <x-ui.badge :classes="$d->decision->badgeClasses()">{{ $d->decision->label() }}</x-ui.badge>
                                    <span class="text-[11px] text-slate-400 font-numeric">{{ $d->created_at->format('d M Y H:i') }}</span>
                                </div>
                                @if ($d->comment)<p class="text-sm text-slate-700 mt-2 whitespace-pre-line">{{ $d->comment }}</p>@endif
                                <p class="text-[11px] text-slate-500 mt-2">{{ $d->mode->label() }}@if ($d->memo_reference) · memo {{ $d->memo_reference }} of {{ Ui::date($d->memo_date) }}@endif · by {{ $d->recorder?->name }}</p>
                                @if ($d->document)<a href="{{ route('documents.show', $d->document) }}" class="text-[11px] font-semibold text-emerald-700 hover:underline"><i class="fa-solid fa-paperclip mr-1"></i>Scanned decision</a>@endif
                            </div>
                        @empty
                            <p class="text-sm text-slate-400">No decision yet.</p>
                        @endforelse

                        {{-- The CEO's instructions to Finance (imprest) and Procurement (tickets), and how far each has got. --}}
                        @php
                            $sentKinds = $a->instructions->map(fn ($i) => $i->kind->value)->all();
                            $canInstruct = $can('instructions.send') && in_array($s, [ActivityStatus::Approved, ActivityStatus::Postponed, ActivityStatus::InProgress], true) && count($sentKinds) < count(\App\Enums\InstructionKind::cases());
                        @endphp
                        @if ($a->instructions->isNotEmpty() || $canInstruct)
                            <div class="flex items-center justify-between mt-5 mb-2">
                                <h3 class="{{ Ui::MICRO }}">Finance and Procurement</h3>
                                @if ($canInstruct)<button type="button" wire:click="$set('showInstruct', true)" class="{{ Ui::BTN_TINT }}"><i class="fa-solid fa-paper-plane text-[9px]"></i>Instruct</button>@endif
                            </div>
                            @forelse ($a->instructions as $ins)
                                <div class="border border-slate-100 rounded-xl p-4 mb-2" wire:key="ins-{{ $ins->id }}">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-sm font-semibold text-slate-700"><i class="fa-solid {{ $ins->kind->icon() }} text-xs text-slate-400 mr-1"></i>{{ $ins->kind->office() }}: {{ mb_strtolower($ins->kind->label()) }}</span>
                                        <x-ui.badge :classes="$ins->status->badgeClasses()">{{ $ins->status->label() }}</x-ui.badge>
                                    </div>
                                    @if ($ins->note)<p class="text-sm text-slate-700 mt-2 whitespace-pre-line">{{ $ins->note }}</p>@endif
                                    <p class="text-[11px] text-slate-500 mt-2">To {{ $ins->department?->name }} · sent {{ Ui::date($ins->sent_at) }}@if ($ins->acknowledged_at) · acknowledged {{ Ui::date($ins->acknowledged_at) }}@endif @if ($ins->completed_at) · done {{ Ui::date($ins->completed_at) }} by {{ $ins->completer?->name }}, {{ mb_strtolower($ins->kind->referenceLabel()) }} {{ $ins->completion_reference }}@endif</p>
                                    @if ($ins->completion_note)<p class="text-[12px] text-slate-500 mt-1 whitespace-pre-line">{{ $ins->completion_note }}</p>@endif
                                </div>
                            @empty
                                <p class="text-sm text-slate-400">Finance and Procurement have not been instructed.</p>
                            @endforelse
                        @endif
                    </div>
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="{{ Ui::MICRO }}">Directives</h3>
                            @can('directives.manage')<button type="button" wire:click="$set('showDirective', true)" class="{{ Ui::BTN_TINT }}"><i class="fa-solid fa-plus text-[9px]"></i>Directive</button>@endcan
                        </div>
                        @forelse ($a->directives as $d)
                            <div class="border border-slate-100 rounded-xl p-4 mb-2" wire:key="dir-{{ $d->id }}">
                                <p class="text-sm text-slate-700 whitespace-pre-line">{{ $d->body }}</p>
                                <p class="text-[11px] text-slate-500 mt-2">{{ $d->department?->name ?? 'No department' }}@if ($d->due_on) · due {{ Ui::date($d->due_on) }}@endif · {{ ucfirst($d->status) }}@if ($d->isOverdue())<span class="text-red-600 font-semibold"> · overdue</span>@endif</p>
                            </div>
                        @empty
                            <p class="text-sm text-slate-400">No directives on this activity.</p>
                        @endforelse
                        <a href="{{ route('directives.index') }}" wire:navigate class="text-[11px] font-semibold text-emerald-700 hover:underline">All directives</a>
                    </div>
                </div>
            @endif

            {{-- ── Report ───────────────────────────────────────────── --}}
            @if ($tab === 'report')
                @if ($a->report_due_on)
                    <div class="mb-4 flex flex-wrap items-center gap-2 text-[13px] text-slate-600">
                        <span>Report due to the CEO by <strong>{{ Ui::date($a->report_due_on) }}</strong>.</span>
                        @php $t = $a->reportTimeliness(); @endphp
                @if ($t)
                    <x-ui.badge :classes="match ($t['status']) { 'on_time' => 'bg-emerald-50 text-emerald-700', 'due' => 'bg-amber-50 text-amber-700', default => 'bg-red-50 text-red-700' }" icon="fa-clock">{{ $a->reportTimelinessLabel() }}</x-ui.badge>
                @endif
                    </div>
                @endif
                @if ($a->report)
                    @if ($a->report->isSubmitted())
                        <div class="mb-4 px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-[13px]"><i class="fa-solid fa-hourglass-half mr-1"></i>Submitted by the Head of Department on {{ $a->report->submitted_at?->format('d M Y') }} with the actual amounts on the Costs tab, which already count as spent. Accept it, or return it for correction.</div>
                    @elseif ($a->report->isReturned())
                        <div class="mb-4 px-3 py-2 rounded-lg bg-orange-50 border border-orange-200 text-orange-800 text-[13px]"><i class="fa-solid fa-reply mr-1"></i>Returned for correction by {{ $a->report->reviewer?->name }} on {{ $a->report->reviewed_at?->format('d M Y') }}: {{ $a->report->return_reason }}</div>
                    @endif
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div><dt class="{{ Ui::MICRO }}">Received</dt><dd class="text-sm text-slate-700 font-numeric">{{ Ui::date($a->report->received_on) }} (due {{ Ui::date($a->report_due_on) }})</dd></div>
                        <div><dt class="{{ Ui::MICRO }}">{{ $a->report->submitted_by ? 'Submitted by' : 'Recorded by' }}</dt><dd class="text-sm text-slate-700">{{ $a->report->recorder?->name }}@if ($a->report->reviewer && ! $a->report->isReturned())<span class="text-slate-400"> · accepted by {{ $a->report->reviewer->name }}</span>@endif</dd></div>
                        <div class="sm:col-span-2"><dt class="{{ Ui::MICRO }}">Expected outputs</dt><dd class="text-sm text-slate-500 whitespace-pre-line">{{ $a->expected_outputs ?: '—' }}</dd></div>
                        <div class="sm:col-span-2"><dt class="{{ Ui::MICRO }}">Outputs achieved</dt><dd class="text-sm text-slate-700 whitespace-pre-line">{{ $a->report->outputs_achieved }}</dd></div>
                        @if ($a->report->findings)<div class="sm:col-span-2"><dt class="{{ Ui::MICRO }}">Key findings</dt><dd class="text-sm text-slate-700 whitespace-pre-line">{{ $a->report->findings }}</dd></div>@endif
                        @if ($a->report->recommendations)<div class="sm:col-span-2"><dt class="{{ Ui::MICRO }}">Recommendations</dt><dd class="text-sm text-slate-700 whitespace-pre-line">{{ $a->report->recommendations }}</dd></div>@endif
                        @if ($a->report->document)<div><a href="{{ route('documents.show', $a->report->document) }}" class="text-[13px] font-semibold text-emerald-700 hover:underline"><i class="fa-solid fa-paperclip mr-1"></i>{{ $a->report->document->original_name }}</a></div>@endif
                    </dl>
                @else
                    <x-ui.empty icon="fa-file-circle-question" title="No report recorded" :line="$a->report_due_on ? 'The back-to-office report is due '.Ui::date($a->report_due_on).'.' : 'A report due date is set when the activity is completed.'" />
                @endif
            @endif

            {{-- ── History ──────────────────────────────────────────── --}}
            @if ($tab === 'history')
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                    <div>
                        <h3 class="{{ Ui::MICRO }} mb-2">Status trail</h3>
                        <ol class="relative border-l border-slate-200 ml-2">
                            @foreach ($a->statusHistory->reverse() as $h)
                                <li class="ml-4 mb-4" wire:key="h-{{ $h->id }}">
                                    <span class="absolute -left-1.5 mt-1.5 w-3 h-3 rounded-full bg-emerald-500 border-2 border-white"></span>
                                    <p class="text-[13px] text-slate-700"><x-ui.status :status="$h->to_status" />@if ($h->from_status)<span class="text-[11px] text-slate-400"> from {{ $h->from_status->label() }}</span>@endif</p>
                                    @if ($h->reason)<p class="text-[13px] text-slate-600 mt-1">{{ $h->reason }}</p>@endif
                                    <p class="text-[11px] text-slate-400 font-numeric">{{ $h->actorLabel() }} · {{ $h->created_at->format('d M Y H:i') }}</p>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                    <div>
                        <h3 class="{{ Ui::MICRO }} mb-2">Amendments after submission</h3>
                        @forelse ($a->amendments as $m)
                            <div class="border border-slate-100 rounded-xl p-3 mb-2" wire:key="am-{{ $m->id }}">
                                <p class="text-[13px] font-medium text-slate-700">{{ $m->summary }}</p>
                                <p class="text-[13px] text-slate-600">Reason: {{ $m->reason }}</p>
                                <p class="text-[11px] text-slate-400 font-numeric">{{ $m->user?->name }} · {{ $m->created_at->format('d M Y H:i') }}@if ($m->returned_for_decision)<span class="text-amber-700 font-semibold"> · returned to the CEO</span>@endif</p>
                            </div>
                        @empty
                            <p class="text-sm text-slate-400">No amendments.</p>
                        @endforelse
                        <p class="text-[11px] text-slate-400 mt-3">Recorded by {{ $a->creator?->name ?? $a->submitted_by_name ?? 'System' }} on {{ $a->created_at->format('d M Y H:i') }}. Field-level changes are in the audit log.</p>
                    </div>
                </div>
            @endif
        </div>
    </section>

    {{-- ═════════════════════ Popups (PAKA-RANGI §5) ═════════════════════ --}}

    <x-ui.modal show="showDecide" :open="$showDecide" :size="$decision === 'approved' ? 'slim' : 'medium'" :tone="$decision === 'declined' ? 'red' : 'emerald'" icon="fa-gavel"
        :title="DecisionType::from($decision)->actionLabel().': '.$a->reference" :subtitle="$decision === 'approved' ? 'Approve this activity as planned.' : 'A comment is required. It is shown to the Chief of Staff and Assistant.'">
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5">
            <div class="{{ $cell }}"><p class="{{ Ui::MICRO }}">Dates</p><p class="text-[13px] font-bold font-numeric">{{ Ui::dateRange($a->start_date, $a->end_date) }}</p></div>
            <div class="{{ $cell }}"><p class="{{ Ui::MICRO }}">Team</p><p class="text-[13px] font-bold font-numeric">{{ $a->staff_count }} officers</p></div>
            <div class="{{ $cell }} border-emerald-200 bg-emerald-50"><p class="{{ Ui::MICRO }}">Planned cost</p><p class="text-[13px] font-bold font-numeric">{{ Money::format(Money::toCents($a->estimated_total)) }}</p></div>
        </div>
        @if (count($conflicts))<p class="text-[13px] text-red-600"><i class="fa-solid fa-triangle-exclamation mr-1"></i>{{ count($conflicts) }} participant(s) overlap another approved activity.</p>@endif
        @if ($decision === 'approved')
            <x-memo.budget-position :position="$budget" />
            <p class="text-[12px] text-slate-500"><a href="{{ route('memos.document', $a) }}" target="_blank" rel="noopener" class="font-semibold text-emerald-700 hover:underline">Open the memo and annexes</a> to read the itinerary, detailed budget and budget breakdown.</p>
        @endif
        <x-ui.field :label="$decision === 'approved' ? 'Comment (optional)' : 'Directive / comment'" for="comment" error="comment" :required="$decision !== 'approved'">
            <textarea id="comment" wire:model="comment" rows="3" class="{{ Ui::CONTROL }}"></textarea>
        </x-ui.field>
        @if ($decision === 'approved')
            @can('instructions.send')<x-memo.instruct :sent="$a->instructions->map(fn ($i) => $i->kind->value)->all()" />@endcan
        @endif
        <x-slot:footer>
            <button type="button" wire:click="$set('showDecide', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="decide" wire:loading.attr="disabled" wire:target="decide" class="{{ $decision === 'declined' ? Ui::BTN_DANGER : Ui::BTN_PRIMARY }}"><i class="fa-solid fa-gavel text-xs"></i><span wire:loading.remove wire:target="decide">{{ DecisionType::from($decision)->actionLabel() }}</span><span wire:loading wire:target="decide">Saving…</span></button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showRecordDecision" :open="$showRecordDecision" icon="fa-file-signature" title="Record the CEO's decision" subtitle="For a decision the CEO gave outside the system. It will be labelled as recorded on the CEO's behalf.">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <x-ui.field label="Decision" for="rd-d" error="decision" required>
                <select id="rd-d" wire:model.live="decision" class="{{ $sel }}">@foreach (DecisionType::cases() as $d)<option value="{{ $d->value }}">{{ $d->label() }}</option>@endforeach</select>
            </x-ui.field>
            <x-ui.field label="Memo reference" for="rd-m" error="memoReference" required><input id="rd-m" type="text" wire:model="memoReference" maxlength="100" class="{{ Ui::CONTROL }}"></x-ui.field>
            <x-ui.field label="Memo date" for="rd-dt" error="memoDate" required><input id="rd-dt" type="date" wire:model="memoDate" class="{{ Ui::CONTROL }}"></x-ui.field>
            <x-ui.field label="Scanned copy" for="rd-s" error="scan" required hint="PDF or image, up to 10 MB.">
                <input id="rd-s" type="file" wire:model="scan" accept=".pdf,.jpg,.jpeg,.png" class="block w-full text-[13px] text-slate-600 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:font-semibold">
            </x-ui.field>
            <x-ui.field label="Comment or directive" for="rd-c" error="comment" :required="$decision !== 'approved'" class="sm:col-span-2"><textarea id="rd-c" wire:model="comment" rows="3" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        </div>
        <x-slot:footer>
            <button type="button" wire:click="$set('showRecordDecision', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="recordDecision" wire:loading.attr="disabled" wire:target="recordDecision,scan" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-floppy-disk text-xs"></i>Record decision</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showPostpone" :open="$showPostpone" icon="fa-calendar-xmark" :title="$s === ActivityStatus::Postponed ? 'Reschedule' : 'Postpone'" subtitle="Give new dates if known. A move beyond the tolerance in Settings returns the activity to the CEO.">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <x-ui.field label="New start date" for="pp-s" error="newStart" hint="Leave blank if not yet known."><input id="pp-s" type="date" wire:model="newStart" class="{{ Ui::CONTROL }}"></x-ui.field>
            <x-ui.field label="Number of days" for="pp-d" error="newDays" :hint="'Currently '.$a->days"><input id="pp-d" type="number" min="1" max="120" wire:model="newDays" class="{{ Ui::CONTROL }} font-numeric"></x-ui.field>
            <x-ui.field label="Reason" for="pp-r" error="reason" required class="sm:col-span-2"><textarea id="pp-r" wire:model="reason" rows="3" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        </div>
        <x-slot:footer>
            <button type="button" wire:click="$set('showPostpone', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="postpone" wire:loading.attr="disabled" wire:target="postpone" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-calendar-check text-xs"></i>Save</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showExtend" :open="$showExtend" size="slim" icon="fa-calendar-plus" title="Extend the activity" subtitle="Costs are recalculated. An extension beyond tolerance returns the activity to the CEO.">
        <x-ui.field label="Extra days" for="ex-d" error="extraDays" required><input id="ex-d" type="number" min="1" max="60" wire:model="extraDays" class="{{ Ui::CONTROL }} font-numeric"></x-ui.field>
        <x-ui.field label="Reason" for="ex-r" error="reason" required><textarea id="ex-r" wire:model="reason" rows="3" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showExtend', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="extend" wire:loading.attr="disabled" wire:target="extend" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-calendar-plus text-xs"></i>Extend</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showCancel" :open="$showCancel" size="alert" tone="red" icon="fa-ban" title="Cancel this activity?" subtitle="It will not take place. Costs already incurred stay recorded. This cannot be undone.">
        <x-ui.field label="Reason" for="cn-r" error="reason" required><textarea id="cn-r" wire:model="reason" rows="2" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showCancel', false)" class="{{ Ui::BTN_NEUTRAL }}">Keep it</button>
            <button type="button" wire:click="cancel" wire:loading.attr="disabled" wire:target="cancel" class="{{ Ui::BTN_DANGER }}"><i class="fa-solid fa-ban text-xs"></i>Cancel activity</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showForward" :open="$showForward" size="medium" icon="fa-paper-plane" :title="$forwardsToChiefOfStaff ? 'Forward to the Chief of Staff?' : 'Forward to the CEO?'" :subtitle="$a->reference.' · '.$a->title">
        <p class="text-[13px] text-slate-600">
            @if ($forwardsToChiefOfStaff)
                The Chief of Staff reviews it next, then forwards it to the CEO.
            @else
                It goes to the CEO's decision queue. Forwarding is not an approval: only the CEO approves and authorises.
            @endif
        </p>
        <x-ui.field label="Recommendation" for="f-rec" error="recommendation">
            <select id="f-rec" wire:model="recommendation" class="{{ $sel }}"><option value="">No recommendation</option>@foreach ($recommendationOptions as $r)<option value="{{ $r->value }}">{{ $r->label() }}</option>@endforeach</select>
        </x-ui.field>
        <x-ui.field label="Review comment (optional)" for="f-text" error="reviewText" hint="Kept in the memo's history as written.">
            <textarea id="f-text" wire:model="reviewText" rows="3" class="{{ Ui::CONTROL }}" maxlength="2000"></textarea>
        </x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showForward', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="forward" wire:loading.attr="disabled" wire:target="forward" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-paper-plane text-xs"></i>{{ $forwardsToChiefOfStaff ? 'Forward to Chief of Staff' : 'Forward to CEO' }}</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showReturnHod" :open="$showReturnHod" size="medium" icon="fa-reply" title="Return for correction?" :subtitle="($a->responsibleHodLabel() ?? 'The Head of Department').' can change the memo and resubmit it.'">
        <x-ui.field label="What must be corrected" for="r-text" error="reviewText" required hint="Be specific, e.g. 'Add the justification: why the review is needed this quarter.'">
            <textarea id="r-text" wire:model="reviewText" rows="4" class="{{ Ui::CONTROL }}" maxlength="2000"></textarea>
        </x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showReturnHod', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="returnToHod" wire:loading.attr="disabled" wire:target="returnToHod" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-reply text-xs"></i>Return for correction</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showNote" :open="$showNote" size="slim" icon="fa-comment" title="Add a review comment" subtitle="Comments are kept as written and cannot be edited or deleted.">
        <x-ui.field label="Comment" for="n-text" error="reviewText" required>
            <textarea id="n-text" wire:model="reviewText" rows="4" class="{{ Ui::CONTROL }}" maxlength="2000"></textarea>
        </x-ui.field>
        <x-ui.field label="Recommendation" for="n-rec" error="recommendation">
            <select id="n-rec" wire:model="recommendation" class="{{ $sel }}"><option value="">None</option>@foreach ($recommendationOptions as $r)<option value="{{ $r->value }}">{{ $r->label() }}</option>@endforeach</select>
        </x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showNote', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="addNote" wire:loading.attr="disabled" wire:target="addNote" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-comment text-xs"></i>Add comment</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showDiscard" :open="$showDiscard" size="alert" tone="red" icon="fa-trash" title="Discard this draft?" subtitle="Only drafts can be discarded. It is kept in the audit trail.">
        <x-slot:footer>
            <button type="button" wire:click="$set('showDiscard', false)" class="{{ Ui::BTN_NEUTRAL }}">Keep</button>
            <button type="button" wire:click="discard" wire:loading.attr="disabled" wire:target="discard" class="{{ Ui::BTN_DANGER }}"><i class="fa-solid fa-trash text-xs"></i>Discard</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showComplete" :open="$showComplete" size="slim" icon="fa-flag-checkered" title="Mark completed" subtitle="The back-to-office report due date is set from the end date.">
        <x-ui.field label="Actual end date" for="cp-d" error="actualDate" :hint="'Leave blank for the planned end date, '.Ui::date($a->end_date).'.'"><input id="cp-d" type="date" wire:model="actualDate" class="{{ Ui::CONTROL }}"></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showComplete', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="complete" wire:loading.attr="disabled" wire:target="complete" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-flag-checkered text-xs"></i>Mark completed</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showReturnReport" :open="$showReturnReport" size="slim" icon="fa-reply" title="Return the report for correction" subtitle="The Head of Department corrects the report or the amounts spent and submits it again.">
        <x-ui.field label="What needs to be corrected" for="rr-r" error="reason" required><textarea id="rr-r" wire:model="reason" rows="4" maxlength="2000" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showReturnReport', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="returnReport" wire:loading.attr="disabled" wire:target="returnReport" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-reply text-xs"></i>Return report</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showReport" :open="$showReport" icon="fa-file-circle-check" title="Record the back-to-office report" subtitle="Compare outputs achieved with the expected outputs.">
        @if ($a->expected_outputs)<div class="rounded-lg bg-slate-50 border border-slate-200 p-3 text-[13px] text-slate-600"><span class="{{ Ui::MICRO }} block">Expected outputs</span>{{ $a->expected_outputs }}</div>@endif
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <x-ui.field label="Date received" for="rp-d" error="reportReceivedOn" required><input id="rp-d" type="date" wire:model="reportReceivedOn" class="{{ Ui::CONTROL }}"></x-ui.field>
            <x-ui.field label="Report file" for="rp-f" error="reportFile"><input id="rp-f" type="file" wire:model="reportFile" accept=".pdf,.doc,.docx" class="block w-full text-[13px] text-slate-600 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:font-semibold"></x-ui.field>
            <x-ui.field label="Outputs achieved" for="rp-o" error="outputsAchieved" required class="sm:col-span-2"><textarea id="rp-o" wire:model="outputsAchieved" rows="3" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
            <x-ui.field label="Key findings" for="rp-k" error="findings" class="sm:col-span-2"><textarea id="rp-k" wire:model="findings" rows="2" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
            <x-ui.field label="Recommendations" for="rp-r" error="recommendations" class="sm:col-span-2"><textarea id="rp-r" wire:model="recommendations" rows="2" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        </div>
        <x-slot:footer>
            <button type="button" wire:click="$set('showReport', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="recordReport" wire:loading.attr="disabled" wire:target="recordReport,reportFile" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-floppy-disk text-xs"></i>Record report</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showClose" :open="$showClose" size="alert" icon="fa-lock" title="Close this activity?" subtitle="Requires the report, attendance for everyone and an actual amount on every cost line. It then becomes read only.">
        <x-slot:footer>
            <button type="button" wire:click="$set('showClose', false)" class="{{ Ui::BTN_NEUTRAL }}">Not yet</button>
            <button type="button" wire:click="close" wire:loading.attr="disabled" wire:target="close" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-lock text-xs"></i>Close</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showAddStaff" :open="$showAddStaff" size="large" icon="fa-user-plus" title="Add officers" subtitle="Filter the staff list, pick people one by one, a whole department or everyone shown. Your picks are kept while you change the filters.">
        @php
            $selectedIds = array_map('intval', $selectedStaff);
            $selCount = count($selectedIds);
        @endphp

        {{-- Filters --}}
        <div class="flex flex-col lg:flex-row gap-3">
            <div class="relative flex items-center flex-1 min-w-[12rem]">
                <i class="fa-solid fa-magnifying-glass absolute left-3 text-gray-400 text-xs pointer-events-none"></i>
                <input type="search" wire:model.live.debounce.300ms="staffSearch" placeholder="Search name, PF number or email…" class="{{ Ui::CONTROL }} pl-9" aria-label="Search staff">
            </div>
            <select wire:model.live="staffDepartment" class="{{ $sel }} lg:w-60 shrink-0" aria-label="Department">
                <option value="">All departments</option>
                @foreach ($picker['departments'] ?? [] as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
                <option value="none">No department</option>
            </select>
            <select wire:model.live="staffOffice" class="{{ $sel }} lg:w-52 shrink-0" aria-label="Duty station">
                <option value="">All duty stations</option>
                @foreach ($picker['offices'] ?? [] as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach
            </select>
            <select wire:model.live="staffSort" class="{{ $sel }} lg:w-52 shrink-0" aria-label="Order">
                <option value="name">By name</option>
                <option value="fewest">Fewest field days first</option>
            </select>
            <select wire:model="role" class="{{ $sel }} lg:w-44 shrink-0" aria-label="Role for the people added">
                @foreach (ParticipantRole::cases() as $r)<option value="{{ $r->value }}">Add as {{ $r->label() }}</option>@endforeach
            </select>
        </div>

        {{-- Bulk actions --}}
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-[13px] text-slate-500 font-numeric mr-1">{{ $candidates->count() }} shown{{ $candidates->count() === 500 ? ' (first 500, narrow the filters)' : '' }}</span>
            <button type="button" wire:click="selectShown" wire:loading.attr="disabled" class="{{ Ui::BTN_TINT }}" @disabled($candidates->isEmpty())><i class="fa-solid fa-check-double text-[9px]"></i>Select all shown</button>
            <button type="button" wire:click="clearShown" wire:loading.attr="disabled" class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-slate-600 bg-white hover:bg-slate-50 border border-slate-200 px-2.5 py-1.5 rounded-lg" @disabled(($picker['shownSelected'] ?? 0) === 0)><i class="fa-solid fa-xmark text-[9px]"></i>Clear shown</button>
            <label class="ml-auto inline-flex items-center gap-2 text-[13px] text-slate-600 cursor-pointer {{ $selCount ? '' : 'opacity-50' }}">
                <input type="checkbox" wire:model.live="showSelectedOnly" @disabled(! $selCount) class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                Show selected only
            </label>
        </div>

        {{-- Selected, as removable chips --}}
        @if ($selCount)
            <div class="flex flex-wrap items-center gap-1.5 rounded-lg bg-emerald-50/60 border border-emerald-100 px-3 py-2">
                <span class="{{ Ui::MICRO }} !text-emerald-700 mr-1">{{ $selCount }} selected</span>
                @foreach (($picker['selectedStaff'] ?? collect())->take(12) as $st)
                    <span class="inline-flex items-center gap-1 pl-2 pr-1 py-0.5 rounded-full bg-white border border-emerald-200 text-[12px] text-slate-700" wire:key="chip-{{ $st->id }}">
                        {{ $st->name }}
                        <button type="button" wire:click="unselect({{ $st->id }})" class="w-4 h-4 rounded-full text-slate-400 hover:text-red-600 hover:bg-red-50 flex items-center justify-center" aria-label="Remove {{ $st->name }}"><i class="fa-solid fa-xmark text-[9px]"></i></button>
                    </span>
                @endforeach
                @if ($selCount > 12)
                    <button type="button" wire:click="$set('showSelectedOnly', true)" class="text-[12px] font-semibold text-emerald-700 hover:underline">+{{ $selCount - 12 }} more</button>
                @endif
            </div>
        @endif

        {{-- Staff, grouped by department --}}
        <div class="border border-slate-200 rounded-xl max-h-[26rem] overflow-y-auto">
            @forelse ($picker['groups'] ?? [] as $group)
                @php
                    $all = $group['selected'] === $group['rows']->count();
                    $some = $group['selected'] > 0 && ! $all;
                @endphp
                <div wire:key="grp-{{ $group['key'] }}">
                    <div class="sticky top-0 z-10 flex items-center gap-3 px-4 py-2 bg-slate-50 border-b border-slate-200">
                        <input type="checkbox" wire:click="toggleDepartment('{{ $group['key'] }}')" @checked($all)
                            x-data x-effect="$el.indeterminate = {{ $some ? 'true' : 'false' }}"
                            class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" aria-label="Select everyone shown in {{ $group['name'] }}">
                        <span class="text-[12px] font-semibold text-slate-700">{{ $group['name'] }}</span>
                        <span class="text-[11px] text-slate-400 font-numeric">{{ $group['selected'] }} of {{ $group['rows']->count() }} selected</span>
                        <button type="button" wire:click="toggleDepartment('{{ $group['key'] }}')" class="ml-auto text-[11px] font-semibold text-emerald-700 hover:underline">{{ $all ? 'Clear department' : 'Select all in department' }}</button>
                    </div>
                    <div class="divide-y divide-slate-100">
                        @foreach ($group['rows'] as $c)
                            @php $st = $c['staff']; $checked = in_array($st->id, $selectedIds, true); @endphp
                            <div class="px-4 py-2.5 {{ $checked ? 'bg-emerald-50/50' : '' }}" wire:key="cand-{{ $st->id }}">
                                <label class="flex items-start gap-3 cursor-pointer">
                                    <input type="checkbox" value="{{ $st->id }}" wire:model.live="selectedStaff" class="mt-1 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-[13px] font-medium text-slate-700">{{ $st->name }} <span class="text-slate-400 font-numeric">{{ $st->staff_number }}</span></span>
                                        <span class="block text-[11px] text-slate-400">{{ $st->designation?->name ?? 'No designation' }} · {{ $st->job_grade ?: 'no job grade (DSA cannot be computed)' }}</span>
                                        @php $overYear = $picker['yearLimit'] > 0 && $c['yearDays'] + $a->days > $picker['yearLimit']; @endphp
                                        <span class="block text-[11px] font-numeric {{ $overYear ? 'text-amber-700' : 'text-slate-500' }}" title="Planned field days on approved activities in {{ $picker['yearLabel'] }}, not counting this one">
                                            <i class="fa-solid fa-person-walking-luggage mr-1"></i>{{ $c['yearDays'] }} field {{ Str::plural('day', $c['yearDays']) }} in {{ $picker['yearLabel'] }}@if ($picker['yearLimit'] > 0) of {{ $picker['yearLimit'] }}@endif
                                            @if ($overYear) · would pass the limit with this activity @endif
                                        </span>
                                        @if ($c['conflicts']->isNotEmpty())<span class="block text-[11px] text-red-600"><i class="fa-solid fa-triangle-exclamation mr-1"></i>On {{ $c['conflicts']->implode(', ') }} during these dates</span>@endif
                                    </span>
                                </label>
                                @if ($checked && $c['conflicts']->isNotEmpty())
                                    <input type="text" wire:model="conflictReasons.{{ $st->id }}" placeholder="{{ $sameConflictReason ? 'Uses the shared reason below unless you type one' : 'Reason to proceed despite the overlap' }}" maxlength="500" class="{{ Ui::CONTROL }} mt-2 ml-7 !w-[calc(100%-1.75rem)]">
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <p class="px-4 py-8 text-center text-sm text-slate-400">
                    @if ($showSelectedOnly) Nobody selected matches these filters.
                    @elseif ($staffSearch || $staffDepartment || $staffOffice) No active staff match these filters.
                    @else No active staff left to add. The Chief of Staff can import the HR staff list. @endif
                </p>
            @endforelse
        </div>
        @error('selectedStaff')<p class="text-xs text-red-600">{{ $message }}</p>@enderror

        @if (($picker['selectedWithOverlap'] ?? 0) > 0)
            <x-ui.field :label="'Reason for the '.$picker['selectedWithOverlap'].' '.Str::plural('person', $picker['selectedWithOverlap']).' with an overlap'" for="as-same" hint="Applies to everyone selected who is already on another approved activity on these dates, unless you gave them their own reason above.">
                <input id="as-same" type="text" wire:model.live.debounce.400ms="sameConflictReason" maxlength="500" class="{{ Ui::CONTROL }}" placeholder="e.g. The CEO asked for both teams">
            </x-ui.field>
        @endif
        @if ($amend)
            <x-ui.field label="Reason for changing the team" for="as-r" error="reason" required hint="The activity has been submitted or approved, so this is recorded as an amendment."><input id="as-r" type="text" wire:model="reason" maxlength="1000" class="{{ Ui::CONTROL }}"></x-ui.field>
        @endif
        <x-slot:footer>
            <span class="mr-auto text-[13px] text-slate-500 font-numeric">{{ $selCount }} selected</span>
            <button type="button" wire:click="$set('showAddStaff', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="addStaff" wire:loading.attr="disabled" wire:target="addStaff" class="{{ Ui::BTN_PRIMARY }}" @disabled(! $selCount)><i class="fa-solid fa-user-plus text-xs"></i>Add {{ $selCount ?: '' }} to team</button>
        </x-slot:footer>
    </x-ui.modal>

    @php $editingDays = $participantId ? $a->participants->firstWhere('id', $participantId) : null; @endphp
    <x-ui.modal show="showDays" :open="$showDays" size="slim" icon="fa-calendar-day" :title="'Days for '.($editingDays?->displayName() ?? 'participant')"
        :subtitle="'Within the activity, '.Ui::dateRange($a->start_date, $a->end_date).'. DSA follows this person\'s own nights.'">
        <div class="grid grid-cols-2 gap-4">
            <x-ui.field label="First day" for="pd-s" error="daysStart" required><input id="pd-s" type="date" wire:model.live="daysStart" min="{{ $a->start_date->toDateString() }}" max="{{ $a->end_date->toDateString() }}" class="{{ Ui::CONTROL }}"></x-ui.field>
            <x-ui.field label="Number of days" for="pd-n" error="daysCount" required><input id="pd-n" type="number" min="1" max="{{ $a->days }}" wire:model.live="daysCount" class="{{ Ui::CONTROL }} font-numeric"></x-ui.field>
        </div>
        @php
            $pdStart = $daysStart ? \Illuminate\Support\Carbon::parse($daysStart) : null;
            $pdEnd = $pdStart && $daysCount >= 1 ? $pdStart->copy()->addDays($daysCount - 1) : null;
            $pdOver = $pdEnd && $pdEnd->gt($a->end_date);
        @endphp
        <div class="rounded-lg border px-3 py-2.5 {{ $pdOver ? 'border-amber-200 bg-amber-50' : 'border-emerald-200 bg-emerald-50' }}" aria-live="polite">
            <p class="{{ Ui::MICRO }}">Last day · nights</p>
            <p class="text-[13px] font-bold font-numeric {{ $pdOver ? 'text-amber-800' : 'text-emerald-800' }}">
                {{ $pdEnd ? Ui::date($pdEnd).' · '.max(0, $daysCount - 1).' '.Str::plural('night', max(0, $daysCount - 1)) : '—' }}
                <span class="font-normal text-slate-500">of {{ $a->days }} activity days</span>
            </p>
            @if ($pdOver)<p class="text-[11px] text-amber-800 mt-0.5">Runs past the activity's last day, {{ Ui::date($a->end_date) }}.</p>@endif
        </div>
        <button type="button" wire:click="$set('daysStart', '{{ $a->start_date->toDateString() }}')" class="{{ Ui::BTN_TINT }} w-fit">Start on the activity's first day</button>
        @if ($daysOverlap->isNotEmpty())
            <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-[12px] text-red-700" role="alert">
                <i class="fa-solid fa-triangle-exclamation mr-1"></i>On these days {{ $editingDays?->displayName() }} is also on {{ $daysOverlap->map(fn ($o) => $o->reference.' ('.Ui::dateRange($o->start_date, $o->end_date).')')->implode(', ') }}.
            </div>
            @unless ($editingDays?->conflict_reason)
                <x-ui.field label="Reason to proceed despite the overlap" for="pd-o" error="overlapReason" required><input id="pd-o" type="text" wire:model="overlapReason" maxlength="500" class="{{ Ui::CONTROL }}"></x-ui.field>
            @endunless
        @endif
        @if ($amend)<x-ui.field label="Reason for the change" for="pd-r" error="reason" required hint="The activity has been submitted or approved, so this is recorded as an amendment."><input id="pd-r" type="text" wire:model="reason" maxlength="1000" class="{{ Ui::CONTROL }}"></x-ui.field>@endif
        <x-slot:footer>
            <button type="button" wire:click="$set('showDays', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="saveDays" wire:loading.attr="disabled" wire:target="saveDays" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-floppy-disk text-xs"></i>Save days</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showExplain" :open="$showExplain" size="slim" icon="fa-triangle-exclamation" :title="'Overlap: '.($explaining?->displayName() ?? 'participant')"
        subtitle="This person is also on another approved activity on some of these days. Record why they may be on both, or remove them from one.">
        @if ($explaining && isset($conflicts[$explaining->id]))
            <ul class="text-[13px] text-slate-600 space-y-1">
                @foreach ($conflicts[$explaining->id] as $other)
                    <li><a href="{{ route('activities.show', $other) }}" wire:navigate class="font-semibold text-emerald-700 hover:underline font-numeric">{{ $other->reference }}</a> {{ $other->title }} · <span class="font-numeric">{{ Ui::dateRange($other->start_date, $other->end_date) }}</span></li>
                @endforeach
            </ul>
        @endif
        <x-ui.field label="Reason" for="ex-r" error="overlapReason" required hint="Shown to the CEO with the conflict. Approval is refused while an overlap has no reason.">
            <input id="ex-r" type="text" wire:model="overlapReason" maxlength="500" class="{{ Ui::CONTROL }}" placeholder="e.g. Joins the second team after the first two days">
        </x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showExplain', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="explainConflict" wire:loading.attr="disabled" wire:target="explainConflict" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-floppy-disk text-xs"></i>Save reason</button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Recall, release or reassignment --}}
    <x-ui.modal show="showRelease" :open="$showRelease" size="large" icon="fa-person-walking-arrow-loop-left" :title="'Recall, release or reassign '.($releasing?->displayName() ?? '')"
        :subtitle="$releasing ? 'On this activity '.Ui::dateRange($releasing->startOn($a), $releasing->endOn($a)).'. They stay on it up to their last day and are free for other activities from the next day. Nothing is deleted.' : null">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <x-ui.field label="Type" for="rl-k" error="releaseKind" required>
                <select id="rl-k" wire:model.live="releaseKind" class="{{ $sel }}">@foreach ($releaseKinds as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
            </x-ui.field>
            <x-ui.field label="Last day on this activity" for="rl-d" error="releaseLastDay" required>
                <input id="rl-d" type="date" wire:model.live="releaseLastDay" class="{{ Ui::CONTROL }}" @if ($releasing) min="{{ $releasing->startOn($a)->toDateString() }}" max="{{ $releasing->endOn($a)->copy()->subDay()->toDateString() }}" @endif>
            </x-ui.field>
            <x-ui.field label="Asked for by" for="rl-i" error="releaseInitiatedBy" required hint="The officer who initiated it, e.g. Director, Finance.">
                <input id="rl-i" type="text" wire:model="releaseInitiatedBy" maxlength="255" class="{{ Ui::CONTROL }}">
            </x-ui.field>
        </div>
        <x-ui.field label="Reason" for="rl-r" error="releaseReason" required>
            <textarea id="rl-r" wire:model="releaseReason" rows="2" maxlength="1000" class="{{ Ui::CONTROL }}"></textarea>
        </x-ui.field>
        @if ($releaseKind === 'reassignment')
            <x-ui.field label="Reassigned to" for="rl-to" error="releaseDestination" required hint="They are added to that activity from the day after their last day here.">
                <select id="rl-to" wire:model="releaseDestination" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($destinations as $d)<option value="{{ $d->id }}">{{ $d->reference }} · {{ Str::limit($d->title, 60) }} · {{ Ui::dateRange($d->start_date, $d->end_date) }}</option>@endforeach</select>
            </x-ui.field>
        @endif

        @if ($releaseError)
            <p class="text-[13px] text-red-600" role="alert"><i class="fa-solid fa-circle-xmark mr-1"></i>{{ $releaseError }}</p>
        @elseif ($releasePreview)
            <div class="rounded-lg border border-slate-200">
                <div class="px-4 py-2.5 border-b border-slate-100 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-[13px] font-semibold text-slate-700">Effect on the budget, before you confirm</p>
                    <p class="text-[12px] text-slate-500">{{ $releasePreview['days_before'] }} → {{ $releasePreview['days_after'] }} days · available from {{ $releasePreview['available_from']->format('d M Y') }}</p>
                </div>
                <table class="w-full">
                    <thead class="border-b border-slate-100"><tr><th class="{{ Ui::TH }}">Cost line</th><th class="{{ Ui::TH }} text-right">Approved plan</th><th class="{{ Ui::TH }} text-right">After</th><th class="{{ Ui::TH }}">Treatment</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($releasePreview['lines'] as $line)
                            <tr wire:key="rlp-{{ $line['id'] ?? 'extra' }}">
                                <td class="{{ Ui::TD }}">{{ $line['category'] }}@if ($line['description'])<span class="block text-[11px] text-slate-400">{{ $line['description'] }}</span>@endif</td>
                                <td class="{{ Ui::TD }} text-right font-numeric">{{ Money::format($line['before']) }}</td>
                                <td class="{{ Ui::TD }} text-right font-numeric">
                                    @if ($line['id'] && ! $a->costs->firstWhere('id', $line['id'])?->is_computed)
                                        <input type="text" inputmode="decimal" wire:model.live.debounce.500ms="releaseRevised.{{ $line['id'] }}" placeholder="{{ Money::format($line['after']) }}" aria-label="Revised amount" class="{{ Ui::CONTROL }} !py-1 w-32 text-right font-numeric">
                                        @error('releaseRevised.'.$line['id'])<p class="text-[11px] text-red-600">{{ $message }}</p>@enderror
                                    @else
                                        {{ Money::format($line['after']) }}
                                    @endif
                                </td>
                                <td class="{{ Ui::TD }} text-[12px]">{{ match ($line['treatment']) { 'reduced' => 'Reduced: DSA for the nights now spent', 'revised' => 'Revised by you', 'added' => 'Additional cost', default => 'Remains payable' } }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t-2 border-slate-200 bg-slate-50">
                        <tr><td class="px-4 py-2 text-[13px] font-bold">Officer's planned cost</td><td class="px-4 py-2 text-right text-[13px] font-bold font-numeric">{{ Money::format($releasePreview['before']) }}</td><td class="px-4 py-2 text-right text-[13px] font-bold font-numeric">{{ Money::format($releasePreview['after']) }}</td>
                            <td class="px-4 py-2 text-[13px] font-bold {{ $releasePreview['change'] < 0 ? 'text-emerald-700' : ($releasePreview['change'] > 0 ? 'text-amber-700' : '') }}">{{ $releasePreview['change'] === 0 ? 'No change' : ($releasePreview['change'] < 0 ? 'Decrease ' : 'Increase ').'KES '.Money::format(abs($releasePreview['change'])) }}</td></tr>
                    </tfoot>
                </table>
                <div class="px-4 py-2.5 border-t border-slate-100 text-[12px] text-slate-600 space-y-1">
                    <p>Activity planned total: KES {{ Money::format($releasePreview['activity_before']) }} → <strong>KES {{ Money::format($releasePreview['activity_after']) }}</strong>@if ($releasePreview['approved'] !== null) (approved at KES {{ Money::format($releasePreview['approved']) }})@endif.</p>
                    @if ($releasePreview['recovery'] > 0)<p>A reduction is a projected saving, not money returned. If the amounts were already paid out, KES {{ Money::format($releasePreview['recovery']) }} would need to be recovered through Finance.</p>@endif
                    @if ($releasePreview['change'] > 0)<p class="text-amber-700">An increase beyond the cost tolerance in Settings sends the activity back to the CEO.</p>@endif
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <x-ui.field label="Additional cost (optional)" for="rl-xc" error="releaseExtraCategory" hint="For example a return ticket.">
                    <select id="rl-xc" wire:model.live="releaseExtraCategory" class="{{ $sel }}"><option value="">None</option>@foreach ($costCategories->where('code', '!=', 'dsa') as $cc)<option value="{{ $cc->id }}">{{ $cc->name }}</option>@endforeach</select>
                </x-ui.field>
                <x-ui.field label="Amount (KES)" for="rl-xa" error="releaseExtraAmount"><input id="rl-xa" type="text" inputmode="decimal" wire:model.live.debounce.500ms="releaseExtraAmount" class="{{ Ui::CONTROL }} font-numeric"></x-ui.field>
                <x-ui.field label="Description" for="rl-xd" error="releaseExtraDescription"><input id="rl-xd" type="text" wire:model.live.debounce.500ms="releaseExtraDescription" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
            </div>
            <x-ui.field label="Why the other costs change" for="rl-rr" error="releaseRevisionReason" hint="Needed when you revise a line or add a cost. Lines you leave blank remain payable as approved.">
                <input id="rl-rr" type="text" wire:model="releaseRevisionReason" maxlength="1000" class="{{ Ui::CONTROL }}">
            </x-ui.field>
        @endif
        <x-slot:footer>
            <button type="button" wire:click="$set('showRelease', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="release" wire:loading.attr="disabled" wire:target="release" class="{{ Ui::BTN_PRIMARY }}" @disabled($releaseError !== null)><i class="fa-solid fa-check text-xs"></i><span wire:loading.remove wire:target="release">Confirm</span><span wire:loading wire:target="release">Saving…</span></button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showAddExternal" :open="$showAddExternal" size="slim" icon="fa-user-tie" title="Add an external participant" subtitle="Someone who is not Board staff, e.g. an MP or NGCDFC member.">
        <x-ui.field label="Name" for="ext-n" error="externalName" required><input id="ext-n" type="text" wire:model="externalName" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
        <x-ui.field label="Organisation" for="ext-o" error="externalOrganisation"><input id="ext-o" type="text" wire:model="externalOrganisation" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
        <div class="grid grid-cols-2 gap-4">
            <x-ui.field label="Category" for="ext-c" error="externalCategory"><select id="ext-c" wire:model="externalCategory" class="{{ $sel }}">@foreach (ExternalCategory::cases() as $e)<option value="{{ $e->value }}">{{ $e->label() }}</option>@endforeach</select></x-ui.field>
            <x-ui.field label="Role" for="ext-r" error="role"><select id="ext-r" wire:model="role" class="{{ $sel }}">@foreach (ParticipantRole::cases() as $r)<option value="{{ $r->value }}">{{ $r->label() }}</option>@endforeach</select></x-ui.field>
        </div>
        @if ($amend)<x-ui.field label="Reason for the change" for="ext-why" error="reason" required><input id="ext-why" type="text" wire:model="reason" class="{{ Ui::CONTROL }}"></x-ui.field>@endif
        <x-slot:footer>
            <button type="button" wire:click="$set('showAddExternal', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="addExternal" wire:loading.attr="disabled" wire:target="addExternal" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-plus text-xs"></i>Add</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showRemoveParticipant" :open="$showRemoveParticipant" size="alert" tone="red" icon="fa-user-minus" :title="'Remove '.($removing?->displayName() ?? 'participant').'?'" subtitle="Their cost lines are removed with them.">
        @if ($amend)<x-ui.field label="Reason" for="rm-r" error="reason" required><input id="rm-r" type="text" wire:model="reason" class="{{ Ui::CONTROL }}"></x-ui.field>@endif
        <x-slot:footer>
            <button type="button" wire:click="$set('showRemoveParticipant', false)" class="{{ Ui::BTN_NEUTRAL }}">Keep</button>
            <button type="button" wire:click="removeParticipant" wire:loading.attr="disabled" wire:target="removeParticipant" class="{{ Ui::BTN_DANGER }}"><i class="fa-solid fa-user-minus text-xs"></i>Remove</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showCost" :open="$showCost" icon="fa-coins" :title="$costId ? 'Edit planned cost' : 'Add a planned cost'" subtitle="Per participant (air fare, public transport) or for the whole activity (fuel, venue).">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <x-ui.field label="Cost type" for="ct-c" error="costCategoryId" required>
                <select id="ct-c" wire:model.live="costCategoryId" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($costCategories->where('code', '!=', 'dsa') as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
            </x-ui.field>
            <x-ui.field label="For" for="ct-p" error="costParticipantId">
                <select id="ct-p" wire:model="costParticipantId" class="{{ $sel }}"><option value="">Whole activity (shared)</option>@foreach ($a->participants as $p)<option value="{{ $p->id }}">{{ $p->displayName() }}</option>@endforeach</select>
            </x-ui.field>
            <x-ui.field label="Planned amount (KES)" for="ct-a" error="costAmount" required><input id="ct-a" type="text" inputmode="decimal" wire:model="costAmount" placeholder="0.00" class="{{ Ui::CONTROL }} font-numeric"></x-ui.field>
            <x-ui.field label="Travel mode" for="ct-t" error="costTravelMode">
                <select id="ct-t" wire:model="costTravelMode" class="{{ $sel }}"><option value="">Not travel</option>@foreach (TravelMode::cases() as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach</select>
            </x-ui.field>
            <x-ui.field label="Description" for="ct-d" error="costDescription" class="sm:col-span-2"><input id="ct-d" type="text" wire:model="costDescription" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
            @if ($amend)<x-ui.field label="Reason for the change" for="ct-r" error="reason" required class="sm:col-span-2"><input id="ct-r" type="text" wire:model="reason" class="{{ Ui::CONTROL }}"></x-ui.field>@endif
        </div>
        <x-slot:footer>
            <button type="button" wire:click="$set('showCost', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="saveCost" wire:loading.attr="disabled" wire:target="saveCost" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-floppy-disk text-xs"></i>Save</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showOverride" :open="$showOverride" size="slim" icon="fa-pen-to-square" title="Override computed DSA" :subtitle="$overriding ? 'Computed: KES '.Money::format(Money::toCents($overriding->computed_amount)).'. The justification is kept with the line.' : null">
        <x-ui.field label="Amount (KES)" for="ov-a" error="overrideAmount" required><input id="ov-a" type="text" inputmode="decimal" wire:model="overrideAmount" class="{{ Ui::CONTROL }} font-numeric"></x-ui.field>
        <x-ui.field label="Justification" for="ov-r" error="reason" required><textarea id="ov-r" wire:model="reason" rows="2" maxlength="500" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showOverride', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="saveOverride" wire:loading.attr="disabled" wire:target="saveOverride" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-floppy-disk text-xs"></i>Override</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showLocation" :open="$showLocation" size="slim" icon="fa-location-dot" title="Add a location">
        <x-ui.field label="Region" for="lc-r" error="locRegion"><select id="lc-r" wire:model.live="locRegion" class="{{ $sel }}"><option value="">Any</option>@foreach ($regions as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach</select></x-ui.field>
        <x-ui.field label="County" for="lc-c" error="locCounty"><select id="lc-c" wire:model.live="locCounty" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($counties as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-ui.field>
        <x-ui.field label="Constituency" for="lc-k" error="locConstituency"><select id="lc-k" wire:model.live="locConstituency" class="{{ $sel }}" @disabled($constituencies->isEmpty())><option value="">{{ $locCounty ? 'Any' : 'Choose a county first' }}</option>@foreach ($constituencies as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-ui.field>
        <x-ui.field label="Venue" for="lc-v" error="locVenue"><input id="lc-v" type="text" wire:model="locVenue" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showLocation', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="addLocation" wire:loading.attr="disabled" wire:target="addLocation" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-plus text-xs"></i>Add</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showDocument" :open="$showDocument" size="slim" icon="fa-paperclip" title="Attach a document" subtitle="The first document becomes the source document. Files are stored encrypted.">
        <x-ui.field label="File" for="dc-f" error="document" required hint="PDF, Word, Excel or image, up to 10 MB."><input id="dc-f" type="file" wire:model="document" class="block w-full text-[13px] text-slate-600 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:font-semibold"></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showDocument', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="uploadDocument" wire:loading.attr="disabled" wire:target="uploadDocument,document" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-upload text-xs"></i>Attach</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showDirective" :open="$showDirective" icon="fa-list-check" title="Issue a directive" :subtitle="$can('activities.decide') ? 'Tracked to completion by the Chief of Staff.' : 'Recorded as issued on the CEO\'s instruction.'">
        <x-ui.field label="Directive" for="dr-b" error="directiveBody" required><textarea id="dr-b" wire:model="directiveBody" rows="3" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <x-ui.field label="Responsible department" for="dr-d" error="directiveDepartment"><select id="dr-d" wire:model="directiveDepartment" class="{{ $sel }}"><option value="">None</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></x-ui.field>
            <x-ui.field label="Due date" for="dr-due" error="directiveDue"><input id="dr-due" type="date" wire:model="directiveDue" class="{{ Ui::CONTROL }}"></x-ui.field>
        </div>
        <x-slot:footer>
            <button type="button" wire:click="$set('showDirective', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="saveDirective" wire:loading.attr="disabled" wire:target="saveDirective" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-floppy-disk text-xs"></i>Save</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showInstruct" :open="$showInstruct" size="slim" icon="fa-paper-plane" title="Instruct Finance or Procurement" :subtitle="$a->reference.' · approved'">
        <x-memo.instruct :sent="$a->instructions->map(fn ($i) => $i->kind->value)->all()" />
        @error('instruct')<p class="text-xs text-red-600" role="alert">{{ $message }}</p>@enderror
        <x-slot:footer>
            <button type="button" wire:click="$set('showInstruct', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="instructDepartments" wire:loading.attr="disabled" wire:target="instructDepartments" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-paper-plane text-xs"></i>Send</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showImprest" :open="$showImprest" size="slim" icon="fa-receipt" title="Imprest" subtitle="Reference and surrender status only; payment happens outside this system.">
        <x-ui.field label="Imprest or payment reference" for="im-r" error="imprestReference"><input id="im-r" type="text" wire:model="imprestReference" maxlength="100" class="{{ Ui::CONTROL }}" placeholder="{{ $a->imprest_reference }}"></x-ui.field>
        <x-ui.field label="Surrender status" for="im-s" error="imprestStatus"><select id="im-s" wire:model="imprestStatus" class="{{ $sel }}"><option value="">Not set</option>@foreach (ImprestStatus::cases() as $i)<option value="{{ $i->value }}" @selected($a->imprest_status === $i)>{{ $i->label() }}</option>@endforeach</select></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showImprest', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="saveImprest" wire:loading.attr="disabled" wire:target="saveImprest" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-floppy-disk text-xs"></i>Save</button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.page>
