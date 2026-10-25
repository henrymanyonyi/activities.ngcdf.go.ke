{{--
    How the Head of Department signs the memo: their substantive designation from the staff list,
    or "Ag." before it when they act in the position. Unticked by default; binds the Livewire `acting` property.
    Kept on the memo only (activities.hod_acting); the officer's own record never changes.
--}}
@props(['designation'])
<label class="flex items-start gap-2 rounded-lg border border-slate-200 px-3 py-2 text-[13px] text-slate-700">
    <input type="checkbox" wire:model.live="acting" class="mt-0.5 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
    <span>I am signing in an acting capacity
        <span class="block text-[11px] text-slate-500">The memo will read: <strong x-data x-text="$wire.acting ? @js('Ag. '.$designation) : @js($designation)">{{ $designation }}</strong></span>
    </span>
</label>
