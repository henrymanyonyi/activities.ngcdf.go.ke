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

        @if ($s->isFreelyEditable() && $editable)
            <a href="{{ route('activities.edit', $a) }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-pen text-xs"></i>Edit</a>
        @endif
        @if ($s === ActivityStatus::Draft && $can('activities.manage'))
            <button type="button" wire:click="$set('showDiscard', true)" class="{{ Ui::BTN_NEUTRAL }} hover:!text-red-600"><i class="fa-solid fa-trash text-xs"></i>Discard</button>
        @endif
        @if (in_array($s, [ActivityStatus::Draft, ActivityStatus::Returned]) && $can('activities.submit'))
            <button type="button" wire:click="submitForDecision" wire:loading.attr="disabled" wire:target="submitForDecision" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-paper-plane text-xs"></i>Submit to CEO</button>
        @endif
        @if ($s === ActivityStatus::AwaitingDecision && $can('activities.decide'))
            <button type="button" wire:click="openDecide('returned')" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-rotate-left text-xs"></i>Return</button>
            <button type="button" wire:click="openDecide('declined')" class="{{ Ui::BTN_DANGER }}"><i class="fa-solid fa-xmark text-xs"></i>Decline</button>
            <button type="button" wire:click="openDecide('approved')" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-check text-xs"></i>Approve</button>
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
            <button type="button" wire:click="$set('showReport', true)" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-file-circle-check text-xs"></i>Record report</button>
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
    @elseif ($s === ActivityStatus::Returned && $a->status_reason)
        <div class="px-4 py-3 rounded-lg text-sm bg-orange-50 border border-orange-200 text-orange-800"><i class="fa-solid fa-rotate-left mr-2"></i><strong>Returned by the CEO:</strong> {{ $a->status_reason }}</div>
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
                                    <span class="text-sm text-slate-700"><i class="fa-solid fa-location-dot text-emerald-600 mr-2"></i>{{ $loc->label() }}@if ($loc->county?->dsa_destination_category)<span class="text-[11px] text-slate-400"> · DSA: {{ $loc->county->dsa_destination_category }}</span>@endif</span>
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
                            <p class="text-sm text-slate-400">No documents. Attach the memo, concept note or terms of reference.</p>
                        @endforelse
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
                                <th class="{{ Ui::TH }}">Attendance</th><th class="{{ Ui::TH }} text-right">Days</th><th class="{{ Ui::TH }} text-right">Cost incl. share</th><th class="{{ Ui::TH }}"></th>
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
                                            @if (isset($conflicts[$p->id]))
                                                <p class="text-[11px] text-red-600 mt-0.5"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Also on {{ $conflicts[$p->id]->pluck('reference')->implode(', ') }}@if ($p->conflict_reason)<span class="text-slate-500"> · Reason: {{ $p->conflict_reason }}</span>@else<span class="font-semibold"> · no reason given</span>@endif</p>
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
                                        <td class="{{ Ui::TD }} text-right font-numeric">
                                            @if ($p->status === ParticipationStatus::Attended && $can('activities.execute') && ! $s->isReadOnly())
                                                <input type="number" min="0" max="366" value="{{ $p->days_attended ?? $p->days_planned ?? $a->days }}" wire:change="setDaysAttended({{ $p->id }}, $event.target.value)" class="{{ Ui::CONTROL }} !py-1 w-20 text-right" aria-label="Days attended">
                                            @else
                                                {{ $p->days_attended ?? $p->days_planned ?? $a->days }}
                                            @endif
                                        </td>
                                        <td class="{{ Ui::TD }} text-right">
                                            <x-ui.money :value="$row['total']" />
                                            @if ($row['share'])<p class="text-[11px] text-slate-400 font-numeric">incl. {{ Money::format($row['share']) }} shared</p>@endif
                                        </td>
                                        <td class="{{ Ui::TD }} text-right">
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
                    <p class="text-[13px] text-slate-500">DSA is computed from the rate table (job grade × destination, {{ app(\App\Services\AppSettings::class)->get('dsa_basis') }}). Travel and other costs are entered per participant or for the whole activity.</p>
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
                    <x-ui.empty icon="fa-coins" title="No costs yet" line="DSA appears once the team, a location with a destination category and the DSA rates are in place." />
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
                @if ($a->report)
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div><dt class="{{ Ui::MICRO }}">Received</dt><dd class="text-sm text-slate-700 font-numeric">{{ Ui::date($a->report->received_on) }} (due {{ Ui::date($a->report_due_on) }})</dd></div>
                        <div><dt class="{{ Ui::MICRO }}">Recorded by</dt><dd class="text-sm text-slate-700">{{ $a->report->recorder?->name }}</dd></div>
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
        <x-ui.field :label="$decision === 'approved' ? 'Comment (optional)' : 'Directive / comment'" for="comment" error="comment" :required="$decision !== 'approved'">
            <textarea id="comment" wire:model="comment" rows="3" class="{{ Ui::CONTROL }}"></textarea>
        </x-ui.field>
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

    <x-ui.modal show="showAddStaff" :open="$showAddStaff" size="large" icon="fa-user-plus" title="Add officers" subtitle="Choose from the staff list. Anyone already on an approved activity on these dates needs a reason.">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="relative flex items-center sm:col-span-2">
                <i class="fa-solid fa-magnifying-glass absolute left-3 text-gray-400 text-xs pointer-events-none"></i>
                <input type="search" wire:model.live.debounce.300ms="staffSearch" placeholder="Search name, PF number or email…" class="{{ Ui::CONTROL }} pl-9" aria-label="Search staff">
            </div>
            <select wire:model="role" class="{{ $sel }}" aria-label="Role">@foreach (ParticipantRole::cases() as $r)<option value="{{ $r->value }}">{{ $r->label() }}</option>@endforeach</select>
        </div>
        <div class="border border-slate-200 rounded-xl divide-y divide-slate-100 max-h-80 overflow-y-auto">
            @forelse ($candidates as $c)
                @php $st = $c['staff']; $checked = in_array($st->id, array_map('intval', $selectedStaff)); @endphp
                <div class="px-4 py-2.5 {{ $checked ? 'bg-emerald-50/50' : '' }}" wire:key="cand-{{ $st->id }}">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" value="{{ $st->id }}" wire:model.live="selectedStaff" class="mt-1 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[13px] font-medium text-slate-700">{{ $st->name }} <span class="text-slate-400 font-numeric">{{ $st->staff_number }}</span></span>
                            <span class="block text-[11px] text-slate-400">{{ $st->department?->name }}{{ $st->job_grade ? ' · '.$st->job_grade : ' · no job grade (DSA cannot be computed)' }}</span>
                            @if ($c['conflicts']->isNotEmpty())<span class="block text-[11px] text-red-600"><i class="fa-solid fa-triangle-exclamation mr-1"></i>On {{ $c['conflicts']->pluck('reference')->implode(', ') }} during these dates</span>@endif
                        </span>
                    </label>
                    @if ($checked && $c['conflicts']->isNotEmpty())
                        <input type="text" wire:model="conflictReasons.{{ $st->id }}" placeholder="Reason to proceed despite the overlap" maxlength="500" class="{{ Ui::CONTROL }} mt-2 ml-7 !w-[calc(100%-1.75rem)]">
                    @endif
                </div>
            @empty
                <p class="px-4 py-6 text-center text-sm text-slate-400">{{ $staffSearch ? 'No active staff match.' : 'No active staff on the list. The Chief of Staff can import the HR staff list.' }}</p>
            @endforelse
        </div>
        @error('selectedStaff')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
        @if ($amend)
            <x-ui.field label="Reason for changing the team" for="as-r" error="reason" required hint="The activity has been submitted or approved, so this is recorded as an amendment."><input id="as-r" type="text" wire:model="reason" maxlength="1000" class="{{ Ui::CONTROL }}"></x-ui.field>
        @endif
        <x-slot:footer>
            <span class="mr-auto text-[13px] text-slate-500 font-numeric">{{ count($selectedStaff) }} selected</span>
            <button type="button" wire:click="$set('showAddStaff', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="addStaff" wire:loading.attr="disabled" wire:target="addStaff" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-user-plus text-xs"></i>Add to team</button>
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

    <x-ui.modal show="showImprest" :open="$showImprest" size="slim" icon="fa-receipt" title="Imprest" subtitle="Reference and surrender status only; payment happens outside this system.">
        <x-ui.field label="Imprest or payment reference" for="im-r" error="imprestReference"><input id="im-r" type="text" wire:model="imprestReference" maxlength="100" class="{{ Ui::CONTROL }}" placeholder="{{ $a->imprest_reference }}"></x-ui.field>
        <x-ui.field label="Surrender status" for="im-s" error="imprestStatus"><select id="im-s" wire:model="imprestStatus" class="{{ $sel }}"><option value="">Not set</option>@foreach (ImprestStatus::cases() as $i)<option value="{{ $i->value }}" @selected($a->imprest_status === $i)>{{ $i->label() }}</option>@endforeach</select></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showImprest', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="saveImprest" wire:loading.attr="disabled" wire:target="saveImprest" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-floppy-disk text-xs"></i>Save</button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.page>
