@use('App\Support\Ui')
@php $sel = Ui::CONTROL.' appearance-none pr-8'; @endphp

<x-ui.page icon="{{ $activity ? 'fa-pen' : 'fa-plus' }}" :title="$activity ? 'Edit '.$activity->reference : 'Record activity'"
    subtitle="Capture the activity from the departmental memo, work plan or request for approval.">
    <x-slot:actions>
        <a href="{{ $activity ? route('activities.show', $activity) : route('activities.index') }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-arrow-left text-xs"></i>Back</a>
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
                    <x-ui.field label="Objective" for="purpose" error="purpose" required class="sm:col-span-2">
                        <textarea id="purpose" wire:model="purpose" rows="3" class="{{ Ui::CONTROL }}"></textarea>
                    </x-ui.field>
                    <x-ui.field label="Expected outputs" for="outputs" error="expected_outputs" class="sm:col-span-2" hint="What the department will deliver; compared with the back-to-office report.">
                        <textarea id="outputs" wire:model="expected_outputs" rows="2" class="{{ Ui::CONTROL }}"></textarea>
                    </x-ui.field>
                    <x-ui.field label="Notes" for="notes" error="notes" class="sm:col-span-2" hint="Venue, purpose or any other reference detail.">
                        <textarea id="notes" wire:model="notes" rows="2" class="{{ Ui::CONTROL }}"></textarea>
                    </x-ui.field>
                </div>
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
                        <p class="{{ Ui::MICRO }}">End date · nights</p>
                        <p class="text-[13px] font-bold text-emerald-800 font-numeric" aria-live="polite">{{ $endDate ? Ui::date($endDate) : '—' }} · {{ $nights }} {{ Str::plural('night', $nights) }}</p>
                    </div>
                </div>
                @if ($activity && ! $activity->status->isFreelyEditable())
                    <p class="text-[11px] text-amber-700 mt-3"><i class="fa-solid fa-circle-info mr-1"></i>Dates of a submitted or approved activity are changed with Postpone or Extend on the activity page, so the change is recorded.</p>
                @endif
            </x-ui.card>

            @unless ($activity)
                <x-ui.card title="First location" icon="fa-location-dot" padded>
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
                    <p class="text-[11px] text-slate-400 mt-3">More locations can be added on the activity page.</p>
                </x-ui.card>
            @endunless
        </div>

        <div class="space-y-4">
            <x-ui.card title="Source" icon="fa-envelope-open-text" padded>
                <div class="space-y-4">
                    <x-ui.field label="Requesting department" for="dept" error="organising_department_id" required>
                        <select id="dept" wire:model.live="organising_department_id" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
                    </x-ui.field>
                    <x-ui.field label="Memo number" for="ref" error="source_reference">
                        <input id="ref" type="text" wire:model="source_reference" class="{{ Ui::CONTROL }}" maxlength="100" placeholder="e.g. NGCDF/PPME/12/2026">
                    </x-ui.field>
                    <x-ui.field label="Date received" for="recv" error="source_received_on">
                        <input id="recv" type="date" wire:model="source_received_on" class="{{ Ui::CONTROL }}">
                    </x-ui.field>
                    @unless ($activity)
                        <x-ui.field label="Source document" for="memo" error="memo" hint="Memo, concept note or terms of reference (PDF, Word or image, up to 10 MB). Required before submitting to the CEO.">
                            <input id="memo" type="file" wire:model="memo" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="block w-full text-[13px] text-slate-600 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:font-semibold">
                            <div wire:loading wire:target="memo" class="text-[11px] text-slate-400 mt-1">Uploading…</div>
                        </x-ui.field>
                    @endunless
                </div>
            </x-ui.card>

            <x-ui.card title="Costing" icon="fa-coins" padded>
                <div class="space-y-4">
                    <x-ui.field label="Budget line" for="budget" error="budget_line_id" :hint="$budgetLines->isEmpty() ? 'No budget lines recorded for this department and year.' : null">
                        <select id="budget" wire:model="budget_line_id" class="{{ $sel }}"><option value="">None</option>@foreach ($budgetLines as $b)<option value="{{ $b->id }}">{{ $b->label() }}</option>@endforeach</select>
                    </x-ui.field>
                    <label class="flex items-start gap-2.5 text-[13px] text-slate-600">
                        <input type="checkbox" wire:model="count_externals_in_per_head" class="mt-0.5 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                        <span>Count external participants in cost per head<span class="block text-[11px] text-slate-400">Staff-only per-head cost is always shown as well.</span></span>
                    </label>
                </div>
            </x-ui.card>

            <div class="flex justify-end gap-2">
                <a href="{{ $activity ? route('activities.show', $activity) : route('activities.index') }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}">Cancel</a>
                <button type="submit" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="save,memo">
                    <i class="fa-solid fa-floppy-disk text-xs"></i><span wire:loading.remove wire:target="save">{{ $activity ? 'Save changes' : 'Save as draft' }}</span><span wire:loading wire:target="save">Saving…</span>
                </button>
            </div>
        </div>
    </form>
</x-ui.page>
