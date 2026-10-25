@use('App\Support\Ui')
@php
    $sel = Ui::CONTROL.' appearance-none pr-8';
    $back = match (true) {
        ! $office => $activity ? route('memos.show', $activity) : route('memos.index'),
        default => $activity ? route('activities.show', $activity) : route('activities.index'),
    };
@endphp

<x-ui.page icon="{{ $activity ? 'fa-pen' : 'fa-plus' }}" :title="$activity ? 'Edit '.$activity->reference : ($office ? 'Record activity' : 'Create activity memo')"
    :subtitle="$office ? 'Capture the activity from the departmental memo, work plan or request for approval.' : 'Describe the activity your department intends to carry out. It stays a draft, visible only to your department, until you submit it for review.'">
    <x-slot:actions>
        <a href="{{ $back }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-arrow-left text-xs"></i>Back</a>
    </x-slot:actions>

    <form wire:submit="save" class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div class="xl:col-span-2 space-y-4">
            <x-ui.card title="The activity" icon="fa-clipboard-list" padded>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-ui.field label="Title" for="title" error="title" required class="sm:col-span-2">
                        <input id="title" type="text" wire:model="title" class="{{ Ui::CONTROL }}" placeholder="e.g. FY2026/27 Proposal Review, Baringo" maxlength="255">
                    </x-ui.field>
                    <x-ui.field label="Category" for="cat" error="activity_category_id" required>
                        <select id="cat" wire:model.live="activity_category_id" class="{{ $sel }}">
                            <option value="">Choose…</option>
                            @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field label="Activity type" for="type" error="activity_type_id">
                        <select id="type" wire:model="activity_type_id" class="{{ $sel }}" @disabled($types->isEmpty())>
                            <option value="">{{ $activity_category_id ? 'Choose…' : 'Choose a category first' }}</option>
                            @foreach ($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field label="Purpose (objective)" for="purpose" error="purpose" required class="sm:col-span-2" hint="What the activity is for, in a sentence or two.">
                        <textarea id="purpose" wire:model="purpose" rows="3" class="{{ Ui::CONTROL }}"></textarea>
                    </x-ui.field>
                    <x-ui.field label="Introduction and justification" for="justification" error="justification" class="sm:col-span-2" hint="The narration: background, why the activity is needed now, and any letter or Act it responds to. Section 1.0 of the memo; needed before you submit.">
                        <textarea id="justification" wire:model="justification" rows="5" class="{{ Ui::CONTROL }}"></textarea>
                    </x-ui.field>
                    <x-ui.field label="Expected outcomes and deliverables" for="outputs" error="expected_outputs" class="sm:col-span-2" hint="What the department will deliver; compared with the back-to-office report.">
                        <textarea id="outputs" wire:model="expected_outputs" rows="2" class="{{ Ui::CONTROL }}"></textarea>
                    </x-ui.field>
                    <x-ui.field label="Key risks to be mitigated" for="risks" error="risks" class="sm:col-span-2" hint="Section 3.0 of the memo: what could go wrong if the activity does not take place, or during it.">
                        <textarea id="risks" wire:model="risks" rows="3" class="{{ Ui::CONTROL }}"></textarea>
                    </x-ui.field>
                    <x-ui.field label="Participants and stakeholders" for="audience" error="target_audience" class="sm:col-span-2" hint="Who takes part or is targeted, e.g. ICT staff and invited departmental representatives.">
                        <input id="audience" type="text" wire:model="target_audience" class="{{ Ui::CONTROL }}" maxlength="255">
                    </x-ui.field>
                    <x-ui.field label="Notes" for="notes" error="notes" class="sm:col-span-2" hint="Venue, purpose or any other reference detail.">
                        <textarea id="notes" wire:model="notes" rows="2" class="{{ Ui::CONTROL }}"></textarea>
                    </x-ui.field>
                </div>
            </x-ui.card>

            <x-ui.card title="More memo sections" icon="fa-list-ol" padded>
                <p class="text-[12px] text-slate-500 mb-3">Add a section when the standard memo does not cover something, for example a programme, the statutory basis or a previous visit. Sections follow Key risks in the memo, numbered in order.</p>
                <div class="space-y-3">
                    @foreach ($memo_sections as $i => $section)
                        <div class="rounded-lg border border-slate-200 p-3 space-y-3" wire:key="section-{{ $i }}">
                            <div class="flex items-start gap-2">
                                <x-ui.field label="Section heading" for="sec-h-{{ $i }}" error="memo_sections.{{ $i }}.heading" required class="flex-1">
                                    <input id="sec-h-{{ $i }}" type="text" wire:model="memo_sections.{{ $i }}.heading" maxlength="150" class="{{ Ui::CONTROL }}" placeholder="e.g. Programme of the visit">
                                </x-ui.field>
                                <button type="button" wire:click="removeSection({{ $i }})" class="{{ Ui::BTN_ICON_DANGER }} mt-6" aria-label="Remove this section"><i class="fa-solid fa-xmark text-xs"></i></button>
                            </div>
                            <x-ui.field label="Section text" for="sec-b-{{ $i }}" error="memo_sections.{{ $i }}.body" required>
                                <textarea id="sec-b-{{ $i }}" wire:model="memo_sections.{{ $i }}.body" rows="3" class="{{ Ui::CONTROL }}"></textarea>
                            </x-ui.field>
                        </div>
                    @endforeach
                </div>
                @if (count($memo_sections) < 10)
                    <button type="button" wire:click="addSection" class="{{ Ui::BTN_TINT }} mt-3"><i class="fa-solid fa-plus text-[9px]"></i>Add a section</button>
                @endif
            </x-ui.card>

            <x-ui.card title="Dates" icon="fa-calendar" padded>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <x-ui.field label="Planned start" for="start" error="start_date" required>
                        <input id="start" type="date" wire:model.live="start_date" class="{{ Ui::CONTROL }}" @disabled($activity && ! $activity->status->isFreelyEditable())>
                    </x-ui.field>
                    <x-ui.field label="Number of days" for="days" error="days" required>
                        <input id="days" type="number" min="1" max="120" wire:model.live="days" class="{{ Ui::CONTROL }} font-numeric" @disabled($activity && ! $activity->status->isFreelyEditable())>
                    </x-ui.field>
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2.5">
                        <p class="{{ Ui::MICRO }}">End date · payable nights</p>
                        <p class="text-[13px] font-bold text-emerald-800 font-numeric" aria-live="polite">{{ $endDate ? Ui::date($endDate) : '—' }} · {{ $nights }} {{ Str::plural('night', $nights) }}</p>
                        @if ($period)<p class="text-[11px] text-emerald-700" aria-live="polite">{{ $period->summary() }}</p>@endif
                    </div>
                </div>
                @if ($activity && ! $activity->status->isFreelyEditable())
                    <p class="text-[11px] text-amber-700 mt-3"><i class="fa-solid fa-circle-info mr-1"></i>Dates of a submitted or approved activity are changed with Postpone or Extend on the activity page, so the change is recorded.</p>
                @endif
            </x-ui.card>

            @if (! $activity || $activity->locations()->doesntExist())
                <x-ui.card :title="$office ? 'First location' : 'Venue and location'" icon="fa-location-dot" padded>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-ui.field label="Region" for="region" error="region_id">
                            <select id="region" wire:model.live="region_id" class="{{ $sel }}"><option value="">Any</option>@foreach ($regions as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach</select>
                        </x-ui.field>
                        <x-ui.field label="County" for="county" error="county_id" hint="Sets the DSA destination category.">
                            <select id="county" wire:model.live="county_id" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($counties as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
                        </x-ui.field>
                        <x-ui.field label="Constituency" for="const" error="constituency_id">
                            <select id="const" wire:model.live="constituency_id" class="{{ $sel }}" @disabled($constituencies->isEmpty())><option value="">{{ $county_id ? 'Any' : 'Choose a county first' }}</option>@foreach ($constituencies as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
                        </x-ui.field>
                        <x-ui.field label="Venue" for="venue" error="venue">
                            <input id="venue" type="text" wire:model="venue" class="{{ Ui::CONTROL }}" maxlength="255">
                        </x-ui.field>
                    </div>
                    <p class="text-[11px] text-slate-400 mt-3">{{ $office ? 'More locations can be added on the activity page.' : 'For example, venue: NGCDF Board headquarters.' }}</p>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-4">
            <x-ui.card title="Source" icon="fa-envelope-open-text" padded>
                <div class="space-y-4">
                    @if ($office)
                        <x-ui.field label="Requesting department" for="dept" error="organising_department_id" required>
                            <select id="dept" wire:model.live="organising_department_id" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
                        </x-ui.field>
                        <x-ui.field label="Head of Department" for="hod" error="responsible_hod_id" hint="When you prepare the memo on a Head of Department's behalf. You are recorded as the preparer.">
                            <select id="hod" wire:model.live="responsible_hod_id" class="{{ $sel }}" @disabled($heads->isEmpty())>
                                <option value="">{{ $heads->isEmpty() ? 'No account for this department: type the name below' : 'Choose…' }}</option>
                                @foreach ($heads as $h)<option value="{{ $h->id }}">{{ $h->name }}{{ $h->is_active ? '' : ' (inactive)' }}</option>@endforeach
                            </select>
                        </x-ui.field>
                        @if ($responsible_hod_id === '')
                            <x-ui.field label="Head of Department's name" for="hod-name" error="responsible_hod_name">
                                <input id="hod-name" type="text" wire:model="responsible_hod_name" class="{{ Ui::CONTROL }}" maxlength="255" placeholder="e.g. Head of ICT">
                            </x-ui.field>
                        @endif
                    @else
                        <div>
                            <p class="{{ Ui::LABEL }}">Requesting department</p>
                            <p class="text-sm font-semibold text-slate-700">{{ $departments->firstWhere('id', (int) $organising_department_id)?->name ?? auth()->user()->department?->name }}</p>
                            <p class="text-[11px] text-slate-400 mt-1">You are recorded as the responsible Head of Department.</p>
                        </div>
                    @endif
                    @if ($office)
                        <x-ui.field label="Memo number" for="ref" error="source_reference">
                            <input id="ref" type="text" wire:model="source_reference" class="{{ Ui::CONTROL }}" maxlength="100" placeholder="e.g. NGCDF/PPME/12/2026">
                        </x-ui.field>
                        <x-ui.field label="Date received" for="recv" error="source_received_on">
                            <input id="recv" type="date" wire:model="source_received_on" class="{{ Ui::CONTROL }}">
                        </x-ui.field>
                    @else
                        <x-ui.field label="Memo reference" for="ref" error="source_reference" required hint="Printed as REF NO on the memo.">
                            <input id="ref" type="text" wire:model="source_reference" class="{{ Ui::CONTROL }}" maxlength="100" required placeholder="e.g. NG-CDF BOARD/ICT/004/2026">
                        </x-ui.field>
                        <x-ui.field label="Date issued" for="issued" error="issued_on" required hint="Printed as DATE on the memo.">
                            <input id="issued" type="date" wire:model="issued_on" class="{{ Ui::CONTROL }}" required>
                        </x-ui.field>
                    @endif
                    @unless ($activity)
                        <x-ui.field :label="$office ? 'Source document' : 'Supporting document (optional)'" for="memo" error="memo" :hint="$office ? 'Memo, concept note or terms of reference (PDF, Word or image, up to 10 MB). Required before submitting to the CEO.' : 'The system generates the memo for you. Attach anything that supports it, such as an invitation letter. PDF, Word or image, up to 10 MB.'">
                            <input id="memo" type="file" wire:model="memo" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="block w-full text-[13px] text-slate-600 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:font-semibold">
                            <div wire:loading wire:target="memo" class="text-[11px] text-slate-400 mt-1">Uploading…</div>
                        </x-ui.field>
                    @endunless
                </div>
            </x-ui.card>

            <x-ui.card title="Costing" icon="fa-coins" padded>
                <div class="space-y-4">
                    <x-ui.field label="Estimated budget (KES)" for="requested" error="requested_budget" :hint="$office ? 'The department\'s own estimate. The costed plan comes from the team and cost lines.' : 'Your own estimate. After saving, add the team and cost lines under Team and costs; allowances (DSA) are calculated for you.'">
                        <input id="requested" type="text" inputmode="decimal" wire:model="requested_budget" class="{{ Ui::CONTROL }} font-numeric" placeholder="e.g. 250000">
                    </x-ui.field>
                    <x-ui.field label="Budget line" for="budget" error="budget_line_id" :hint="$budgetLines->isEmpty() ? 'No approved budget for this department and year yet.' : null">
                        <select id="budget" wire:model="budget_line_id" class="{{ $sel }}"><option value="">None</option>@foreach ($budgetLines as $b)<option value="{{ $b->id }}">{{ $b->label() }}</option>@endforeach</select>
                    </x-ui.field>
                    @if ($budget)
                        @php $kes = fn (?int $c) => $c === null ? '—' : 'KES '.\App\Support\Money::format($c); @endphp
                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-[12px] text-slate-600 space-y-1" aria-live="polite">
                            <p class="{{ Ui::MICRO }}">{{ $budget['department']?->name }} budget · FY {{ $budget['financial_year'] }}</p>
                            @if ($budget['allocation'] === null)
                                <p class="text-amber-700">No allocation is recorded for this year yet.</p>
                            @else
                                <p class="flex justify-between"><span>Allocation</span><span class="font-numeric">{{ $kes($budget['allocation']) }}</span></p>
                                <p class="flex justify-between"><span>Spent</span><span class="font-numeric">{{ $kes($budget['spent']) }}</span></p>
                                <p class="flex justify-between"><span>Committed</span><span class="font-numeric">{{ $kes($budget['committed']) }}</span></p>
                                <p class="flex justify-between font-semibold {{ $budget['balance'] < 0 ? 'text-red-600' : 'text-slate-800' }}"><span>Available balance</span><span class="font-numeric">{{ $kes($budget['balance']) }}</span></p>
                            @endif
                            @if ($budget['pending_count'])<p class="text-purple-700">{{ $kes($budget['pending']) }} more is awaiting decision.</p>@endif
                        </div>
                    @endif
                    @if ($office)
                    <label class="flex items-start gap-2.5 text-[13px] text-slate-600">
                        <input type="checkbox" wire:model="count_externals_in_per_head" class="mt-0.5 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                        <span>Count external participants in cost per head<span class="block text-[11px] text-slate-400">Staff-only per-head cost is always shown as well.</span></span>
                    </label>
                    @endif
                </div>
            </x-ui.card>

            <div class="flex justify-end gap-2">
                <a href="{{ $back }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}">Cancel</a>
                <button type="submit" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="save,memo">
                    <i class="fa-solid fa-floppy-disk text-xs"></i><span wire:loading.remove wire:target="save">{{ $activity ? 'Save changes' : 'Save draft' }}</span><span wire:loading wire:target="save">Saving…</span>
                </button>
            </div>
        </div>
    </form>
</x-ui.page>
