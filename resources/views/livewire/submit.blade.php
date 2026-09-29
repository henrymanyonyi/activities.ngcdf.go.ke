@use('App\Support\Ui')
@php $sel = Ui::CONTROL.' appearance-none pr-8'; $file = 'block w-full text-[13px] text-slate-600 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:font-semibold'; @endphp

<div>
    @if ($submittedReference)
        <div class="{{ Ui::CARD }} p-8 text-center">
            <div class="w-14 h-14 rounded-2xl bg-emerald-50 flex items-center justify-center mx-auto mb-3"><i class="fa-solid fa-check text-2xl text-emerald-600"></i></div>
            <h1 class="text-xl font-bold text-slate-800">Received</h1>
            <p class="text-sm text-slate-600 mt-1">Your submission is recorded as <span class="font-numeric font-semibold">{{ $submittedReference }}</span>. The Office of the CEO will review it. Quote this reference in any follow-up.</p>
        </div>
    @elseif (! $usable)
        <div class="{{ Ui::CARD }} p-8 text-center">
            <div class="w-14 h-14 rounded-2xl bg-slate-50 flex items-center justify-center mx-auto mb-3"><i class="fa-solid fa-link-slash text-2xl text-slate-300"></i></div>
            <h1 class="text-xl font-bold text-slate-800">This link is not available</h1>
            <p class="text-sm text-slate-500 mt-1">It may have expired, been used up or withdrawn. Contact the Office of the CEO for a new one.</p>
        </div>
    @else
        <form wire:submit="submit" class="space-y-4">
            <div>
                <h1 class="text-xl font-bold text-slate-800">{{ $link->label }}</h1>
                <p class="text-sm text-slate-500">Send in a planned activity with its memo and participant list. Everything marked * is required.</p>
                @if ($link->instructions)<div class="mt-3 px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200 text-[13px] text-emerald-900 whitespace-pre-line">{{ $link->instructions }}</div>@endif
            </div>

            <x-ui.card title="The activity" padded>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-ui.field label="Title" for="s-t" error="title" required class="sm:col-span-2"><input id="s-t" type="text" wire:model="title" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
                    <x-ui.field label="Category" for="s-c" error="activity_category_id" required><select id="s-c" wire:model.live="activity_category_id" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-ui.field>
                    <x-ui.field label="Type" for="s-ty" error="activity_type_id"><select id="s-ty" wire:model="activity_type_id" class="{{ $sel }}" @disabled($types->isEmpty())><option value="">Choose…</option>@foreach ($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></x-ui.field>
                    <x-ui.field label="Objective" for="s-p" error="purpose" required class="sm:col-span-2"><textarea id="s-p" wire:model="purpose" rows="3" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
                    <x-ui.field label="Expected outputs" for="s-o" error="expected_outputs" class="sm:col-span-2"><textarea id="s-o" wire:model="expected_outputs" rows="2" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
                    <x-ui.field label="Department" for="s-d" error="organising_department_id" required><select id="s-d" wire:model="organising_department_id" class="{{ $sel }}" @disabled($link->department_id)><option value="">Choose…</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></x-ui.field>
                    <x-ui.field label="Memo number" for="s-m" error="source_reference"><input id="s-m" type="text" wire:model="source_reference" maxlength="100" class="{{ Ui::CONTROL }}"></x-ui.field>
                    <x-ui.field label="Start date" for="s-sd" error="start_date" required><input id="s-sd" type="date" wire:model.live="start_date" min="{{ today()->toDateString() }}" class="{{ Ui::CONTROL }}"></x-ui.field>
                    <x-ui.field label="Number of days" for="s-days" error="days" required :hint="$endDate ? 'Ends '.Ui::date($endDate) : null"><input id="s-days" type="number" min="1" max="120" wire:model.live="days" class="{{ Ui::CONTROL }} font-numeric"></x-ui.field>
                    <x-ui.field label="County" for="s-co" error="county_id" required><select id="s-co" wire:model.live="county_id" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($counties as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-ui.field>
                    <x-ui.field label="Constituency" for="s-k" error="constituency_id"><select id="s-k" wire:model="constituency_id" class="{{ $sel }}" @disabled($constituencies->isEmpty())><option value="">Any</option>@foreach ($constituencies as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-ui.field>
                    <x-ui.field label="Venue" for="s-v" error="venue" class="sm:col-span-2"><input id="s-v" type="text" wire:model="venue" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
                </div>
            </x-ui.card>

            <x-ui.card title="Documents" padded>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-ui.field label="Memo" for="s-memo" error="memo" required hint="PDF, Word or image, up to 10 MB."><input id="s-memo" type="file" wire:model="memo" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="{{ $file }}"></x-ui.field>
                    <x-ui.field label="Participant list" for="s-list" error="participantList" required>
                        <input id="s-list" type="file" wire:model="participantList" accept=".xlsx,.xls,.csv" class="{{ $file }}">
                        <a href="{{ route('submit.template', $token) }}" class="text-[11px] font-semibold text-emerald-700 hover:underline"><i class="fa-solid fa-download mr-1"></i>Download the template</a>
                        <div wire:loading wire:target="participantList" class="text-[11px] text-slate-400">Checking the list…</div>
                        @if ($participantList && ! $listErrors && $listRows)<p class="text-[11px] text-emerald-700 mt-1"><i class="fa-solid fa-check mr-1"></i>{{ $listRows }} participants read.</p>@endif
                    </x-ui.field>
                </div>
                @if ($listErrors)
                    <div class="mt-3 rounded-lg bg-red-50 border border-red-200 p-3 max-h-48 overflow-y-auto" role="alert">
                        <p class="text-[13px] font-semibold text-red-700 mb-1">The participant list has problems:</p>
                        @foreach ($listErrors as $e)<p class="text-[12px] text-red-700">{{ $e }}</p>@endforeach
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card title="Estimated costs (optional)" padded>
                <p class="text-[13px] text-slate-500 mb-3">DSA is calculated by the Office of the CEO. Add other costs you know of, such as air fares, fuel or venue.</p>
                @foreach ($costs as $i => $c)
                    <div class="grid grid-cols-1 sm:grid-cols-12 gap-2 mb-2" wire:key="sc-{{ $i }}">
                        <select wire:model="costs.{{ $i }}.cost_category_id" class="{{ $sel }} sm:col-span-4" aria-label="Cost type"><option value="">Type…</option>@foreach ($costCategories as $cc)<option value="{{ $cc->id }}">{{ $cc->name }}</option>@endforeach</select>
                        <input type="text" wire:model="costs.{{ $i }}.description" maxlength="255" placeholder="Description" class="{{ Ui::CONTROL }} sm:col-span-4">
                        <input type="text" inputmode="decimal" wire:model="costs.{{ $i }}.estimated_amount" placeholder="Amount (KES)" class="{{ Ui::CONTROL }} sm:col-span-3 font-numeric">
                        <button type="button" wire:click="removeCost({{ $i }})" class="{{ Ui::BTN_ICON_DANGER }} sm:col-span-1" aria-label="Remove"><i class="fa-solid fa-xmark"></i></button>
                        @error("costs.{$i}.cost_category_id")<p class="text-xs text-red-600 sm:col-span-12">{{ $message }}</p>@enderror
                        @error("costs.{$i}.estimated_amount")<p class="text-xs text-red-600 sm:col-span-12">{{ $message }}</p>@enderror
                    </div>
                @endforeach
                <button type="button" wire:click="addCost" class="{{ Ui::BTN_TINT }}"><i class="fa-solid fa-plus text-[9px]"></i>Add a cost</button>
            </x-ui.card>

            <x-ui.card title="Your details" padded>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <x-ui.field label="Name" for="s-n" error="submitted_by_name" required><input id="s-n" type="text" wire:model="submitted_by_name" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
                    <x-ui.field label="Email" for="s-e" error="submitted_by_email"><input id="s-e" type="email" wire:model="submitted_by_email" class="{{ Ui::CONTROL }}"></x-ui.field>
                    <x-ui.field label="Phone" for="s-ph" error="submitted_by_phone"><input id="s-ph" type="text" wire:model="submitted_by_phone" maxlength="30" class="{{ Ui::CONTROL }}"></x-ui.field>
                </div>
            </x-ui.card>

            <div class="flex justify-end">
                <button type="submit" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="submit,memo,participantList"><i class="fa-solid fa-paper-plane text-xs"></i><span wire:loading.remove wire:target="submit">Submit</span><span wire:loading wire:target="submit">Submitting…</span></button>
            </div>
        </form>
    @endif
</div>
