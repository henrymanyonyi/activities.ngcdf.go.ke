@use('App\Support\Ui')
@use('App\Support\Money')
@php
    $sel = Ui::CONTROL.' appearance-none pr-8';
    $staffTeam = $a->participants->where('is_external', false);
    $externals = $a->participants->where('is_external', true);
    $manual = $a->costs->where('is_computed', false);
    $dsa = $a->costs->where('is_computed', true);
@endphp

<x-ui.page icon="fa-users" title="Team and costs" :subtitle="$a->reference.' · '.$a->title.' · '.Ui::dateRange($a->start_date, $a->end_date)">
    <x-slot:pills><x-ui.status :status="$a->status" /></x-slot:pills>
    <x-slot:actions>
        <a href="{{ route('memos.show', $a) }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-arrow-left text-xs"></i>Back to the memo</a>
        @if ($editable)
            <button type="button" wire:click="$set('showExternal', true)" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-user-tie text-xs"></i>Add external participant</button>
            <button type="button" wire:click="$set('showAdd', true)" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-user-plus text-xs"></i>Add officers</button>
        @endif
    </x-slot:actions>

    <x-slot:stats>
        <x-ui.stat :index="0" icon="fa-users" label="Officers" :value="$a->staff_count" :sub="$a->external_count ? '+'.$a->external_count.' external' : 'staff on the team'" />
        <x-ui.stat :index="1" icon="fa-calendar-days" label="Days · nights" :value="$a->days.' · '.$a->nights" :sub="Ui::dateRange($a->start_date, $a->end_date)" tone="blue" />
        <x-ui.stat :index="2" icon="fa-coins" label="Planned cost" :value="Money::format(Money::toCents($a->estimated_total))" money sub="DSA and the cost lines below" tone="amber" />
        <x-ui.stat :index="3" icon="fa-calculator" label="Your estimate" :value="$a->requested_budget !== null ? Money::format(Money::toCents($a->requested_budget)) : 'Not given'" :money="$a->requested_budget !== null" sub="from the memo" tone="slate" />
    </x-slot:stats>

    @unless ($editable)
        <div class="px-4 py-3 rounded-lg text-sm bg-slate-100 border border-slate-200 text-slate-600"><i class="fa-solid fa-lock mr-2"></i>The memo is with {{ $a->currentHolder() }}. The team and costs can be changed again only if it is returned to you.</div>
    @endunless
    @if ($a->locations->isEmpty() || $a->locations->whereNotNull('county_id')->isEmpty())
        <div class="px-4 py-3 rounded-lg text-sm bg-amber-50 border border-amber-200 text-amber-800"><i class="fa-solid fa-circle-info mr-2"></i>Allowances (DSA) are worked out from the county of the activity. Add a county to the location on the memo page so DSA can be calculated.</div>
    @endif

    <x-ui.card title="Team" icon="fa-users" :count="$a->participants->count()">
        @if ($a->participants->isEmpty())
            <x-ui.empty icon="fa-user-plus" title="No one on the team yet" line="Add the officers taking part. If an officer is not on the staff list, you can add them from the same window." class="!py-10" />
        @else
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="border-b border-slate-100"><tr>
                        <th class="{{ Ui::TH }}">Name</th><th class="{{ Ui::TH }}">Department</th><th class="{{ Ui::TH }}">Job grade</th><th class="{{ Ui::TH }}">Role</th><th class="{{ Ui::TH }}">Days</th><th class="{{ Ui::TH }}"><span class="sr-only">Actions</span></th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($staffTeam->concat($externals) as $p)
                            <tr wire:key="p-{{ $p->id }}">
                                <td class="{{ Ui::TD }}">
                                    <p class="font-medium text-slate-700">{{ $p->displayName() }}</p>
                                    @if ($p->is_external)<p class="text-[11px] text-slate-400">External{{ $p->external_organisation ? ' · '.$p->external_organisation : '' }}</p>@else<p class="text-[11px] text-slate-400 font-numeric">{{ $p->staff?->staff_number }}</p>@endif
                                    @if (in_array($p->id, $overlapping))
                                        <p class="text-[12px] {{ $p->conflict_reason ? 'text-slate-500' : 'text-red-600' }} mt-0.5"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Also on another approved activity on these dates.@if ($p->conflict_reason) Reason: {{ $p->conflict_reason }}@endif</p>
                                    @endif
                                </td>
                                <td class="{{ Ui::TD }}">{{ $p->is_external ? '—' : ($p->department?->name ?? '—') }}</td>
                                <td class="{{ Ui::TD }} font-numeric">{!! $p->is_external ? '—' : ($p->job_grade ? e($p->job_grade) : '<span class="text-amber-600">not set</span>') !!}</td>
                                <td class="{{ Ui::TD }}">{{ $p->role->label() }}</td>
                                <td class="{{ Ui::TD }} font-numeric whitespace-nowrap">{{ $p->plannedDays($a) }}{{ $p->isPartial($a) ? ' ('.Ui::dateRange($p->startOn($a), $p->endOn($a)).')' : '' }}</td>
                                <td class="{{ Ui::TD }} text-right whitespace-nowrap">
                                    @if ($editable)
                                        @if (in_array($p->id, $overlapping) && ! $p->conflict_reason)<button type="button" wire:click="openExplain({{ $p->id }})" class="{{ Ui::BTN_TINT }}">Give reason</button>@endif
                                        @if ($a->days > 1)<button type="button" wire:click="openDays({{ $p->id }})" class="{{ Ui::BTN_TINT }}">Days</button>@endif
                                        <button type="button" wire:click="confirmRemove({{ $p->id }})" class="{{ Ui::BTN_ICON_DANGER }}" aria-label="Remove {{ $p->displayName() }}"><i class="fa-solid fa-xmark text-xs"></i></button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    <x-ui.card title="Planned costs" icon="fa-coins" :count="'KES '.Money::format(Money::toCents($a->estimated_total))">
        @if ($editable)
            <x-slot:actions><button type="button" wire:click="openCost" class="{{ Ui::BTN_TINT }}"><i class="fa-solid fa-plus text-[9px]"></i>Add cost line</button></x-slot:actions>
        @endif
        @if ($a->costs->isEmpty())
            <x-ui.empty icon="fa-coins" title="No costs yet" line="DSA appears here once officers are on the team and the location has a county. Add travel, venue and other costs as cost lines." class="!py-10" />
        @else
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="border-b border-slate-100"><tr>
                        <th class="{{ Ui::TH }}">Cost</th><th class="{{ Ui::TH }}">For</th><th class="{{ Ui::TH }}">Details</th><th class="{{ Ui::TH }} text-right">Amount (KES)</th><th class="{{ Ui::TH }}"><span class="sr-only">Actions</span></th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($dsa->concat($manual) as $c)
                            <tr wire:key="c-{{ $c->id }}">
                                <td class="{{ Ui::TD }}">{{ $c->category?->name }}@if ($c->is_computed)<x-ui.badge classes="bg-emerald-50 text-emerald-700" class="ml-1">Calculated</x-ui.badge>@endif</td>
                                <td class="{{ Ui::TD }}">{{ $c->participant?->displayName() ?? 'Whole activity' }}</td>
                                <td class="{{ Ui::TD }}">{{ $c->description ?: ($c->travel_mode?->label() ?? '—') }}</td>
                                <td class="{{ Ui::TD }} text-right font-numeric">{{ Money::format(Money::toCents($c->estimated_amount)) }}</td>
                                <td class="{{ Ui::TD }} text-right whitespace-nowrap">
                                    @if ($editable && ! $c->is_computed)
                                        <button type="button" wire:click="openCost({{ $c->id }})" class="{{ Ui::BTN_ICON }}" aria-label="Edit cost line"><i class="fa-solid fa-pen text-xs"></i></button>
                                        <button type="button" wire:click="removeCost({{ $c->id }})" wire:loading.attr="disabled" class="{{ Ui::BTN_ICON_DANGER }}" aria-label="Remove cost line"><i class="fa-solid fa-xmark text-xs"></i></button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="px-5 py-3 text-[11px] text-slate-400 border-t border-slate-100">DSA is calculated from each officer's job grade, their nights away and the county; it updates when the team, days or location change. The Office of the CEO can review every figure.</p>
        @endif
    </x-ui.card>

    {{-- Add officers --}}
    <x-ui.modal show="showAdd" :open="$showAdd" size="large" icon="fa-user-plus" title="Add officers" subtitle="Tick the officers taking part. If someone is not on the list, add them to the staff list here.">
        <div class="flex flex-col sm:flex-row gap-3">
            <div class="relative flex items-center flex-1">
                <i class="fa-solid fa-magnifying-glass absolute left-3 text-gray-400 text-xs pointer-events-none"></i>
                <input type="search" wire:model.live.debounce.300ms="staffSearch" placeholder="Search name or PF number…" aria-label="Search staff" class="{{ Ui::CONTROL }} pl-9">
            </div>
            <select wire:model.live="staffDepartment" class="{{ $sel }} sm:w-56" aria-label="Department"><option value="">All departments</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
            <select wire:model="role" class="{{ $sel }} sm:w-44" aria-label="Role">@foreach ($roles as $r)<option value="{{ $r->value }}">{{ $r->label() }}</option>@endforeach</select>
        </div>
        @error('selectedStaff')<p class="text-xs text-red-600" role="alert">{{ $message }}</p>@enderror
        <div class="border border-slate-200 rounded-lg divide-y divide-slate-100 max-h-[45vh] overflow-y-auto">
            @forelse ($candidates as $st)
                @php $ticked = in_array($st->id, $selectedIds); $clash = isset($candidateOverlaps[$st->id]); @endphp
                <div class="px-3 py-2.5 {{ $ticked ? 'bg-emerald-50/50' : '' }}" wire:key="cand-{{ $st->id }}">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" value="{{ $st->id }}" wire:model.live="selectedStaff" class="mt-1 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[13px] font-medium text-slate-700">{{ $st->name }} <span class="text-[11px] text-slate-400 font-numeric">{{ $st->staff_number }}</span></span>
                            <span class="block text-[11px] text-slate-400">{{ $st->designation?->name ?? 'No designation' }} · {{ $st->department?->name ?? 'No department' }} · grade {{ $st->job_grade ?: 'not set' }}</span>
                            @if ($clash)<span class="block text-[12px] text-amber-700"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Already on another approved activity on these dates.</span>@endif
                        </span>
                    </label>
                    @if ($ticked && $clash)
                        <input type="text" wire:model="conflictReasons.{{ $st->id }}" maxlength="500" placeholder="Reason they can take part in both (required)" aria-label="Reason for {{ $st->name }}" class="{{ Ui::CONTROL }} mt-2 ml-7 w-[calc(100%-1.75rem)]">
                    @endif
                </div>
            @empty
                <p class="px-3 py-6 text-center text-[13px] text-slate-400">No officer matches. Check the spelling, or add the officer to the staff list.</p>
            @endforelse
        </div>
        <button type="button" wire:click="openStaffForm" class="{{ Ui::BTN_TINT }}"><i class="fa-solid fa-id-badge text-[9px]"></i>Officer not on the list? Add them to the staff list</button>
        <x-slot:footer>
            <button type="button" wire:click="$set('showAdd', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="addStaff" wire:loading.attr="disabled" wire:target="addStaff" class="{{ Ui::BTN_PRIMARY }}" @disabled($selectedIds === [])><i class="fa-solid fa-user-plus text-xs"></i>Add {{ count($selectedIds) ?: '' }} to team</button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Add an officer missing from the staff list --}}
    <x-ui.modal show="showStaffForm" :open="$showStaffForm" icon="fa-id-badge" title="Add an officer to the staff list" subtitle="Use the details on the officer's HR record. You can correct officers you add from the Staff list page.">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <x-ui.field label="PF number" for="ns-pf" error="staffNumber" required><input id="ns-pf" type="text" wire:model="staffNumber" maxlength="30" class="{{ Ui::CONTROL }} font-numeric"></x-ui.field>
            <x-ui.field label="Name" for="ns-n" error="staffName" required><input id="ns-n" type="text" wire:model="staffName" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
            <x-ui.field label="Department" for="ns-d" error="staffDepartmentId" required><select id="ns-d" wire:model="staffDepartmentId" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></x-ui.field>
            <x-ui.field label="Designation" for="ns-des" error="staffDesignationId"><select id="ns-des" wire:model="staffDesignationId" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($designations as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></x-ui.field>
            <x-ui.field label="Job grade" for="ns-g" error="staffJobGrade" hint="Sets the officer's DSA rate, e.g. NGCDF 5."><input id="ns-g" type="text" wire:model="staffJobGrade" maxlength="20" class="{{ Ui::CONTROL }}"></x-ui.field>
            <x-ui.field label="Duty station" for="ns-o" error="staffOfficeId"><select id="ns-o" wire:model="staffOfficeId" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($offices as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach</select></x-ui.field>
        </div>
        <x-slot:footer>
            <button type="button" wire:click="$set('showStaffForm', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="saveStaff" wire:loading.attr="disabled" wire:target="saveStaff" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-floppy-disk text-xs"></i>Add to staff list</button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- External participant --}}
    <x-ui.modal show="showExternal" :open="$showExternal" size="slim" icon="fa-user-tie" title="Add an external participant" subtitle="Someone who is not NG-CDF Board staff, such as an invited representative.">
        <x-ui.field label="Name" for="ex-n" error="externalName" required><input id="ex-n" type="text" wire:model="externalName" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
        <x-ui.field label="Organisation" for="ex-o" error="externalOrganisation"><input id="ex-o" type="text" wire:model="externalOrganisation" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
        <x-ui.field label="Category" for="ex-c" error="externalCategory" required><select id="ex-c" wire:model="externalCategory" class="{{ $sel }}">@foreach ($externalCategories as $c)<option value="{{ $c->value }}">{{ $c->label() }}</option>@endforeach</select></x-ui.field>
        <x-ui.field label="Role" for="ex-r" error="role" required><select id="ex-r" wire:model="role" class="{{ $sel }}">@foreach ($roles as $r)<option value="{{ $r->value }}">{{ $r->label() }}</option>@endforeach</select></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showExternal', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="addExternal" wire:loading.attr="disabled" wire:target="addExternal" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-user-plus text-xs"></i>Add</button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Remove from the team --}}
    <x-ui.modal show="showRemove" :open="$showRemove" size="alert" tone="red" icon="fa-user-minus" :title="'Remove '.($selected?->displayName() ?? 'this person').' from the team?'" subtitle="Their DSA and any cost lines for them are removed too.">
        <x-slot:footer>
            <button type="button" wire:click="$set('showRemove', false)" class="{{ Ui::BTN_NEUTRAL }}">Keep</button>
            <button type="button" wire:click="removeParticipant" wire:loading.attr="disabled" wire:target="removeParticipant" class="{{ Ui::BTN_DANGER }}"><i class="fa-solid fa-user-minus text-xs"></i>Remove</button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- One person's days --}}
    <x-ui.modal show="showDays" :open="$showDays" size="slim" icon="fa-calendar-days" :title="'Days for '.($selected?->displayName() ?? '')" :subtitle="'The activity runs '.Ui::dateRange($a->start_date, $a->end_date).'.'">
        <div class="grid grid-cols-2 gap-4">
            <x-ui.field label="First day" for="d-s" error="daysStart" required><input id="d-s" type="date" wire:model="daysStart" min="{{ $a->start_date->toDateString() }}" max="{{ $a->end_date->toDateString() }}" class="{{ Ui::CONTROL }}"></x-ui.field>
            <x-ui.field label="Number of days" for="d-c" error="daysCount" required><input id="d-c" type="number" min="1" max="{{ $a->days }}" wire:model="daysCount" class="{{ Ui::CONTROL }} font-numeric"></x-ui.field>
        </div>
        <x-ui.field label="Reason, if these days overlap another activity" for="d-r" error="overlapReason"><input id="d-r" type="text" wire:model="overlapReason" maxlength="500" class="{{ Ui::CONTROL }}"></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showDays', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="saveDays" wire:loading.attr="disabled" wire:target="saveDays" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-floppy-disk text-xs"></i>Save days</button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Explain an overlap --}}
    <x-ui.modal show="showExplain" :open="$showExplain" size="slim" icon="fa-triangle-exclamation" :title="'Why can '.($selected?->displayName() ?? 'this officer').' take part?'" subtitle="They are on another approved activity on these dates. The memo cannot go to the CEO until each overlap has a reason.">
        <x-ui.field label="Reason" for="x-r" error="overlapReason" required><textarea id="x-r" wire:model="overlapReason" rows="3" maxlength="500" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showExplain', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="explainConflict" wire:loading.attr="disabled" wire:target="explainConflict" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-floppy-disk text-xs"></i>Save reason</button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- A planned cost line --}}
    <x-ui.modal show="showCost" :open="$showCost" size="slim" icon="fa-coins" :title="$costId ? 'Edit cost line' : 'Add a cost line'" subtitle="For travel, venue, materials and other costs. DSA is calculated for you.">
        <x-ui.field label="Cost type" for="c-cat" error="costCategoryId" required><select id="c-cat" wire:model="costCategoryId" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($costCategories as $cc)<option value="{{ $cc->id }}">{{ $cc->name }}</option>@endforeach</select></x-ui.field>
        <x-ui.field label="For" for="c-p" error="costParticipantId"><select id="c-p" wire:model="costParticipantId" class="{{ $sel }}"><option value="">The whole activity</option>@foreach ($a->participants as $p)<option value="{{ $p->id }}">{{ $p->displayName() }}</option>@endforeach</select></x-ui.field>
        <x-ui.field label="Travel mode" for="c-t" error="costTravelMode"><select id="c-t" wire:model="costTravelMode" class="{{ $sel }}"><option value="">Not travel</option>@foreach ($travelModes as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach</select></x-ui.field>
        <x-ui.field label="Description" for="c-d" error="costDescription"><input id="c-d" type="text" wire:model="costDescription" maxlength="255" class="{{ Ui::CONTROL }}" placeholder="e.g. Conference room hire, one day"></x-ui.field>
        <x-ui.field label="Amount (KES)" for="c-a" error="costAmount" required><input id="c-a" type="text" inputmode="decimal" wire:model="costAmount" class="{{ Ui::CONTROL }} font-numeric" placeholder="e.g. 45,000.00"></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showCost', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="saveCost" wire:loading.attr="disabled" wire:target="saveCost" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-floppy-disk text-xs"></i>Save</button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.page>
