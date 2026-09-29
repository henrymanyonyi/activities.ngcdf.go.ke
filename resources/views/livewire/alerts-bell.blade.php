<div class="relative" x-data @keydown.escape.window="$wire.set('open', false)" wire:poll.60s>
    <button type="button" wire:click="$toggle('open')" class="relative p-2 rounded-lg text-slate-500 hover:text-emerald-700 hover:bg-slate-100 transition" aria-label="Alerts{{ $unread ? ", {$unread} unread" : '' }}">
        <i class="fa-regular fa-bell"></i>
        @if ($unread)
            <span class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-red-600 text-white text-[10px] font-bold font-numeric flex items-center justify-center">{{ $unread > 99 ? '99+' : $unread }}</span>
        @endif
    </button>

    @if ($open)
        <div wire:click.outside="$set('open', false)" class="absolute right-0 mt-2 w-80 bg-white rounded-xl shadow-2xl border border-slate-200 z-50 overflow-hidden" role="menu">
            <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100 bg-slate-50/60">
                <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">Alerts</span>
                @if ($unread)
                    <button type="button" wire:click="markAllRead" class="text-[11px] font-semibold text-emerald-700 hover:underline">Mark all read</button>
                @endif
            </div>
            <div class="max-h-96 overflow-y-auto divide-y divide-slate-100">
                @forelse ($alerts as $alert)
                    <button type="button" wire:click="openAlert('{{ $alert->id }}')" wire:key="alert-{{ $alert->id }}"
                        class="w-full text-left flex items-start gap-3 px-4 py-3 hover:bg-slate-50 transition {{ $alert->read_at ? '' : 'bg-emerald-50/40' }}">
                        <span class="w-7 h-7 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0"><i class="fa-solid {{ $alert->data['icon'] ?? 'fa-bell' }} text-xs"></i></span>
                        <span class="min-w-0">
                            <span class="block text-[13px] text-slate-700 {{ $alert->read_at ? '' : 'font-semibold' }}">{{ $alert->data['message'] ?? '' }}</span>
                            <span class="block text-[11px] text-slate-400 font-numeric">{{ $alert->created_at->diffForHumans() }}</span>
                        </span>
                    </button>
                @empty
                    <p class="px-4 py-8 text-center text-sm text-slate-400">No alerts.</p>
                @endforelse
            </div>
        </div>
    @endif
</div>
