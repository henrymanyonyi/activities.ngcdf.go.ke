@use('App\Support\Ui')
@php $sel = Ui::CONTROL.' appearance-none pr-8'; @endphp
<x-ui.page icon="fa-link" title="Submission links" subtitle="A link lets a memo originator send in an activity and its participant list. Submissions arrive as drafts for review.">
    <x-slot:actions><button type="button" wire:click="edit" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-plus text-xs"></i>New link</button></x-slot:actions>

    <div class="px-4 py-3 rounded-lg text-[13px] bg-amber-50 border border-amber-200 text-amber-800"><i class="fa-solid fa-triangle-exclamation mr-1"></i>FRD 2.2 and BR-11 put access by departments out of scope. Use links only once the CEO has confirmed this intake route.</div>

    @if ($freshUrl)
        <div class="px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200" x-data="{ copied: false }">
            <p class="text-[13px] font-semibold text-emerald-800">Link ready. Send it privately to the recipient.</p>
            <div class="flex gap-2 mt-2">
                <input type="text" readonly value="{{ $freshUrl }}" class="{{ Ui::CONTROL }} font-numeric flex-1" x-ref="url" aria-label="Link">
                <button type="button" class="{{ Ui::BTN_NEUTRAL }}" @click="navigator.clipboard.writeText($refs.url.value); copied = true"><i class="fa-solid fa-copy text-xs"></i><span x-text="copied ? 'Copied' : 'Copy'"></span></button>
                <button type="button" wire:click="$set('freshUrl', null)" class="{{ Ui::BTN_ICON }}" aria-label="Dismiss"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </div>
    @endif

    <x-ui.card title="Links" :count="$links->count()">
        @forelse ($links as $l)
            <div class="px-5 py-3 border-b border-slate-100 last:border-0 flex flex-col lg:flex-row lg:items-center gap-3" wire:key="l-{{ $l->id }}" x-data="{ copied: false }">
                <div class="min-w-0 flex-1">
                    <p class="text-[13px] font-semibold text-slate-700">{{ $l->label }} <x-ui.badge :classes="$l->statusBadgeClasses()">{{ $l->statusLabel() }}</x-ui.badge></p>
                    <p class="text-[11px] text-slate-400">For {{ $l->recipient_name }}{{ $l->department ? ' · '.$l->department->name : '' }} · expires {{ Ui::date($l->expires_at) }} · {{ $l->submissions_count }}{{ $l->max_submissions ? '/'.$l->max_submissions : '' }} submissions · opened {{ $l->open_count }} times{{ $l->last_opened_at ? ', last '.$l->last_opened_at->diffForHumans() : '' }}</p>
                </div>
                <div class="flex flex-wrap gap-2 shrink-0">
                    @if ($l->activities_count)<a href="{{ route('activities.index', ['flag' => 'from_link']) }}" wire:navigate class="{{ Ui::BTN_TINT }}">{{ $l->activities_count }} received</a>@endif
                    @if ($l->isUsable())<button type="button" class="{{ Ui::BTN_TINT }}" @click="navigator.clipboard.writeText(@js($l->url())); copied = true"><i class="fa-solid fa-copy text-[9px]"></i><span x-text="copied ? 'Copied' : 'Copy link'"></span></button>@endif
                    <button type="button" wire:click="edit({{ $l->id }})" class="{{ Ui::BTN_TINT }}">Edit</button>
                    <button type="button" wire:click="regenerate({{ $l->id }})" class="{{ Ui::BTN_TINT }}" title="Issue a new link; the old one stops working">New link</button>
                    <button type="button" wire:click="toggleRevoked({{ $l->id }})" class="inline-flex items-center text-[11px] font-semibold px-2.5 py-1.5 rounded-lg border {{ $l->isRevoked() ? 'text-emerald-700 bg-emerald-50 border-emerald-200' : 'text-red-700 bg-red-50 border-red-200' }}">{{ $l->isRevoked() ? 'Reactivate' : 'Revoke' }}</button>
                </div>
            </div>
        @empty
            <x-ui.empty icon="fa-link" title="No links yet" line="Create a link for the officer who originates a memo." />
        @endforelse
    </x-ui.card>

    <x-ui.modal show="showForm" :open="$showForm" icon="fa-link" :title="$editingId ? 'Edit link' : 'New submission link'" subtitle="Everything here can be changed later. Revoke or issue a new link at any time.">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <x-ui.field label="Label" for="l-l" error="label" required class="sm:col-span-2"><input id="l-l" type="text" wire:model="label" maxlength="255" placeholder="e.g. PPME Q2 field plan" class="{{ Ui::CONTROL }}"></x-ui.field>
            <x-ui.field label="Recipient" for="l-r" error="recipient_name" required><input id="l-r" type="text" wire:model="recipient_name" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
            <x-ui.field label="Recipient email" for="l-e" error="recipient_email"><input id="l-e" type="email" wire:model="recipient_email" class="{{ Ui::CONTROL }}"></x-ui.field>
            <x-ui.field label="Department (pre-set)" for="l-d" error="department_id"><select id="l-d" wire:model="department_id" class="{{ $sel }}"><option value="">Let them choose</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></x-ui.field>
            <x-ui.field label="Category (pre-set)" for="l-c" error="activity_category_id"><select id="l-c" wire:model="activity_category_id" class="{{ $sel }}"><option value="">Let them choose</option>@foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-ui.field>
            <x-ui.field label="Expires" for="l-x" error="expires_at" required><input id="l-x" type="date" wire:model="expires_at" class="{{ Ui::CONTROL }}"></x-ui.field>
            <x-ui.field label="Maximum submissions" for="l-m" error="max_submissions" hint="Blank for no limit."><input id="l-m" type="number" min="1" max="500" wire:model="max_submissions" class="{{ Ui::CONTROL }} font-numeric"></x-ui.field>
            <x-ui.field label="Instructions shown on the form" for="l-i" error="instructions" class="sm:col-span-2"><textarea id="l-i" wire:model="instructions" rows="3" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        </div>
        <x-slot:footer>
            <button type="button" wire:click="$set('showForm', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-floppy-disk text-xs"></i>{{ $editingId ? 'Save' : 'Create link' }}</button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.page>
