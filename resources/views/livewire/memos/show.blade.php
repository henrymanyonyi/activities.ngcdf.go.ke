@use('App\Support\Ui')
@use('App\Support\Money')
@use('App\Enums\ActivityStatus')
@php
    $s = $a->status;
    $returned = $s === ActivityStatus::ReturnedToHod;
    $hasMemo = $a->documents->contains('kind', \App\Models\ActivityDocument::KIND_MEMO);
@endphp

<x-ui.page icon="fa-envelope-open-text" :title="$a->title" :subtitle="$a->reference.' · '.($a->organisingDepartment?->name ?? 'No department').' · '.Ui::dateRange($a->start_date, $a->end_date)">
    <x-slot:pills><x-ui.status :status="$s" /></x-slot:pills>
    <x-slot:actions>
        <a href="{{ route('memos.index') }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-arrow-left text-xs"></i>Memos</a>
        @if ($editable)
            @if ($canDiscard)
                <button type="button" wire:click="$set('showDiscard', true)" class="{{ Ui::BTN_NEUTRAL }} hover:!text-red-600"><i class="fa-solid fa-trash text-xs"></i>Discard draft</button>
            @endif
            <a href="{{ route('memos.edit', $a) }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-pen text-xs"></i>Edit memo</a>
            <button type="button" wire:click="openSubmit" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-paper-plane text-xs"></i>{{ $returned ? 'Resubmit for review' : 'Submit for review' }}</button>
        @endif
    </x-slot:actions>

    @if ($editable && ! $returned)
        <div class="px-4 py-3 rounded-lg text-sm bg-sky-50 border border-sky-200 text-sky-800"><i class="fa-solid fa-circle-info mr-2"></i>This memo is a draft. Only your department can see it until you press <strong>Submit for review</strong>.</div>
    @elseif ($returned)
        <div class="px-4 py-3 rounded-lg text-sm bg-orange-50 border border-orange-200 text-orange-800"><i class="fa-solid fa-reply mr-2"></i>Returned to you for correction. Make the changes asked for below, then press <strong>Resubmit for review</strong>.</div>
    @elseif ($s->isApprovedOrLater() || $s === ActivityStatus::Declined || $s === ActivityStatus::Cancelled)
        <div class="px-4 py-3 rounded-lg text-sm bg-slate-100 border border-slate-200 text-slate-600"><i class="fa-solid fa-lock mr-2"></i>The CEO has decided on this memo. The decision and its history are below.</div>
    @else
        <div class="px-4 py-3 rounded-lg text-sm bg-slate-100 border border-slate-200 text-slate-600"><i class="fa-solid fa-lock mr-2"></i>This memo is with the {{ $a->currentHolder() }}. You can follow it here; it can be changed again only if it is returned to you.</div>
    @endif

    <x-memo.workflow :activity="$a" />

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
                            <span class="text-slate-400 italic">Not given. Add it with Edit memo.</span>
                        @endforelse
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
                    <x-ui.field :label="$hasMemo ? 'Add a supporting document' : 'Attach the signed memo'" for="doc" error="document" :hint="$hasMemo ? 'PDF, Word, Excel or image, up to 10 MB.' : 'Needed before you can submit. PDF, Word or image, up to 10 MB.'">
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
                <select id="s-office" wire:model="office" class="{{ Ui::CONTROL }} appearance-none pr-8">
                    @foreach ($offices as $o)<option value="{{ $o->value }}">{{ $o->label() }}</option>@endforeach
                </select>
            </x-ui.field>
            <p class="text-[13px] text-slate-600">You cannot change the memo while it is being reviewed. If something needs correcting, the reviewer will return it to you.</p>
        @endif
        <x-ui.field :label="$returned ? 'What you changed (optional)' : 'Note to the reviewer (optional)'" for="s-note" error="note">
            <textarea id="s-note" wire:model="note" rows="3" class="{{ Ui::CONTROL }}" maxlength="2000"></textarea>
        </x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showSubmit', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-paper-plane text-xs"></i><span wire:loading.remove wire:target="submit">{{ $returned ? 'Resubmit for review' : 'Submit for review' }}</span><span wire:loading wire:target="submit">Sending…</span></button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showDiscard" :open="$showDiscard" size="alert" tone="red" icon="fa-trash" title="Discard this draft?" subtitle="It is removed from your list. Nothing has been sent to anyone.">
        <x-slot:footer>
            <button type="button" wire:click="$set('showDiscard', false)" class="{{ Ui::BTN_NEUTRAL }}">Keep it</button>
            <button type="button" wire:click="discard" wire:loading.attr="disabled" wire:target="discard" class="{{ Ui::BTN_DANGER }}"><i class="fa-solid fa-trash text-xs"></i>Discard draft</button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.page>
