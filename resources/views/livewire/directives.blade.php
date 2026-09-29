@use('App\Support\Ui')
@php $sel = Ui::CONTROL.' appearance-none pr-8'; @endphp
<x-ui.page icon="fa-list-check" title="Directives" subtitle="The CEO's directives and action items, tracked to completion." scroll="inner">
    <x-slot:actions>
        @can('directives.manage')<button type="button" wire:click="$set('showNew', true)" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-plus text-xs"></i>New directive</button>@endcan
    </x-slot:actions>
    <x-slot:tabs>
        <div class="flex flex-wrap items-center gap-1 bg-gray-50 border border-gray-200 rounded-lg p-1 w-fit">
            @foreach ($tabs as $key => $cfg)
                @php $on = $status === $key; @endphp
                <button type="button" wire:click="$set('status', '{{ $key }}')" class="inline-flex items-center gap-2 px-4 py-1.5 text-xs font-semibold rounded-md whitespace-nowrap transition {{ $on ? 'bg-emerald-600 text-white' : 'text-slate-500 hover:text-slate-800' }}">
                    <i class="fa-solid {{ $cfg['icon'] }} text-[10px]"></i>{{ $cfg['label'] }}
                    @if ($cfg['count'] > 0)<span class="font-numeric px-1.5 py-0.5 rounded-full text-[10px] {{ $on ? 'bg-white/20 text-white' : 'bg-amber-50 text-amber-700' }}">{{ $cfg['count'] }}</span>@endif
                </button>
            @endforeach
        </div>
    </x-slot:tabs>

    <div class="flex-1 min-h-0 flex flex-col {{ Ui::CARD }}">
        @if ($directives->isEmpty())
            <x-ui.empty icon="fa-list-check" title="No directives here" />
        @else
            <div class="flex-1 min-h-0 overflow-y-auto divide-y divide-slate-100">
                @foreach ($directives as $d)
                    <div class="px-5 py-4 flex flex-col sm:flex-row sm:items-start gap-3" wire:key="d-{{ $d->id }}">
                        <div class="min-w-0 flex-1">
                            <p class="text-[13px] text-slate-700 whitespace-pre-line">{{ $d->body }}</p>
                            <p class="text-[11px] text-slate-400 mt-1">
                                {{ $d->department?->name ?? 'No department' }}
                                @if ($d->activity) · <a href="{{ route('activities.show', $d->activity) }}" wire:navigate class="text-emerald-700 hover:underline">{{ $d->activity->reference }}</a>@endif
                                · issued {{ Ui::date($d->created_at) }} by {{ $d->issuer?->name }}{{ $d->on_ceo_instruction ? ' on the CEO\'s instruction' : '' }}
                            </p>
                            @if ($d->completion_note)<p class="text-[12px] text-emerald-700 mt-1"><i class="fa-solid fa-check mr-1"></i>{{ $d->completion_note }} ({{ Ui::date($d->completed_at) }})</p>@endif
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            @if ($d->due_on)<x-ui.badge :classes="$d->isOverdue() ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-500'" icon="fa-calendar">Due {{ Ui::date($d->due_on) }}</x-ui.badge>@endif
                            @if ($d->status === 'open')
                                @can('directives.manage')<button type="button" wire:click="openComplete({{ $d->id }})" class="{{ Ui::BTN_TINT }}"><i class="fa-solid fa-check text-[9px]"></i>Complete</button>@endcan
                            @else
                                <x-ui.badge classes="bg-emerald-50 text-emerald-700">Completed</x-ui.badge>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            @if ($directives->hasPages())<div class="shrink-0 px-5 py-3 border-t border-slate-100 bg-slate-50">{{ $directives->links('livewire.custom-pagination') }}</div>@endif
        @endif
    </div>

    <x-ui.modal show="showNew" :open="$showNew" icon="fa-list-check" title="New directive" subtitle="Not tied to one activity. Directives on an activity are added from its page.">
        <x-ui.field label="Directive" for="n-b" error="body" required><textarea id="n-b" wire:model="body" rows="3" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <x-ui.field label="Responsible department" for="n-d" error="department"><select id="n-d" wire:model="department" class="{{ $sel }}"><option value="">None</option>@foreach ($departments as $dp)<option value="{{ $dp->id }}">{{ $dp->name }}</option>@endforeach</select></x-ui.field>
            <x-ui.field label="Due date" for="n-due" error="due"><input id="n-due" type="date" wire:model="due" class="{{ Ui::CONTROL }}"></x-ui.field>
        </div>
        <x-slot:footer>
            <button type="button" wire:click="$set('showNew', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="create" wire:loading.attr="disabled" wire:target="create" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-floppy-disk text-xs"></i>Save</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showComplete" :open="$showComplete" size="slim" icon="fa-check" title="Mark directive complete">
        <x-ui.field label="What was done" for="c-n" error="note" required><textarea id="c-n" wire:model="note" rows="3" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showComplete', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="complete" wire:loading.attr="disabled" wire:target="complete" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-check text-xs"></i>Complete</button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.page>
