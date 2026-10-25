{{--
    The CEO's "Instruct Finance / Procurement" tick boxes (App\Livewire\Concerns\SendsInstructions).
    $sent lists the kinds already instructed for this activity, which cannot be sent twice.
--}}
@props(['sent' => []])
@use('App\Enums\InstructionKind')
@use('App\Support\Ui')
@php $service = app(\App\Services\ActionInstructions::class); @endphp
<div class="rounded-lg border border-slate-200 p-3 space-y-2">
    <p class="text-[12px] font-semibold text-slate-700">Instruct the departments that act on the approval</p>
    @foreach (InstructionKind::cases() as $kind)
        @php $department = $service->department($kind); $done = in_array($kind->value, $sent, true); @endphp
        <label class="flex items-start gap-3 {{ $done || ! $department ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer' }}">
            <input type="checkbox" value="{{ $kind->value }}" wire:model.live="instruct" @disabled($done || ! $department) class="mt-0.5 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
            <span class="text-[13px] text-slate-700"><i class="fa-solid {{ $kind->icon() }} text-xs text-slate-400 mr-1"></i>{{ $kind->office() }}: {{ mb_strtolower($kind->label()) }}
                <span class="block text-[11px] text-slate-400">{{ $done ? 'Already instructed.' : ($department ? 'To the Head of '.$department->name.'. They see the dates, places, team and their cost lines, not the memo.' : 'No '.$kind->office().' department is set in Reference data › Thresholds.') }}</span>
            </span>
        </label>
    @endforeach
    <div x-data x-show="$wire.instruct.length > 0" x-cloak>
        <x-ui.field label="Note to them (optional)" for="ins-note" error="instructionNote"><textarea id="ins-note" wire:model="instructionNote" rows="2" maxlength="2000" class="{{ Ui::CONTROL }}" placeholder="e.g. Economy class; imprest to the team lead"></textarea></x-ui.field>
    </div>
</div>
