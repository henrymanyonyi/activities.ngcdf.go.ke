{{--
    Pick the officer on the staff list an account belongs to (App\Livewire\Users::pickStaff).
    Their email and mobile number come with them; the mobile gets the action-needed SMS.
--}}
@props(['matches', 'picked' => null])
@use('App\Support\Ui')
<x-ui.field label="Officer on the staff list" for="u-staff" error="staff_id" hint="Search by name or PF number. Their email and mobile number are picked up from the staff list.">
    @if ($picked)
        <div class="flex items-center justify-between gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2">
            <div class="min-w-0">
                <p class="text-[13px] font-semibold text-slate-800 truncate">{{ $picked->name }}@if ($picked->staff_number) <span class="font-numeric font-normal text-slate-500">· {{ $picked->staff_number }}</span>@endif</p>
                <p class="text-[11px] text-slate-500 truncate">{{ $picked->department?->name ?? 'No department' }} · {{ $picked->email ?: 'no email on the list' }} · {{ $picked->phone ?: 'no mobile on the list' }}</p>
            </div>
            <button type="button" wire:click="clearStaff" class="{{ Ui::BTN_TINT }}">Change</button>
        </div>
    @else
        <input id="u-staff" type="search" wire:model.live.debounce.300ms="staffSearch" class="{{ Ui::CONTROL }}" placeholder="Search name or PF number…" autocomplete="off">
        @if ($matches->isNotEmpty())
            <ul class="mt-1 max-h-48 overflow-y-auto rounded-lg border border-slate-200 divide-y divide-slate-100">
                @foreach ($matches as $s)
                    <li wire:key="pick-{{ $s->id }}">
                        <button type="button" wire:click="pickStaff({{ $s->id }})" class="w-full text-left px-3 py-2 hover:bg-emerald-50">
                            <span class="block text-[13px] font-semibold text-slate-700">{{ $s->name }}@if ($s->staff_number) <span class="font-numeric font-normal text-slate-400">· {{ $s->staff_number }}</span>@endif</span>
                            <span class="block text-[11px] text-slate-400">{{ $s->department?->name ?? 'No department' }}{{ $s->phone ? ' · '.$s->phone : '' }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</x-ui.field>
