{{--
    The CEO's instructions to the departments that act on an approved memo (App\Livewire\Concerns\SendsInstructions):
    tick boxes for Finance and Procurement, and rows for any other department with what it must do.
    $sent lists the Finance or Procurement kinds already instructed for this activity, which cannot be sent twice.
--}}
@props(['sent' => [], 'others' => []])
@use('App\Enums\InstructionKind')
@use('App\Support\Ui')
@php
    $service = app(\App\Services\ActionInstructions::class);
    $departments = \App\Models\Department::query()->active()->ordered()->get(['id', 'name']);
@endphp
<div class="rounded-lg border border-slate-200 p-3 space-y-2">
    <p class="text-[12px] font-semibold text-slate-700">Instruct the departments that act on the approval</p>
    @foreach ([InstructionKind::Imprest, InstructionKind::Tickets] as $kind)
        @php $department = $service->department($kind); $done = in_array($kind->value, $sent, true); @endphp
        <label class="flex items-start gap-3 {{ $done || ! $department ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer' }}">
            <input type="checkbox" value="{{ $kind->value }}" wire:model.live="instruct" @disabled($done || ! $department) class="mt-0.5 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
            <span class="text-[13px] text-slate-700"><i class="fa-solid {{ $kind->icon() }} text-xs text-slate-400 mr-1"></i>{{ $kind->office() }}: {{ mb_strtolower($kind->label()) }}
                <span class="block text-[11px] text-slate-400">{{ $done ? 'Already instructed.' : ($department ? 'To the Head of '.$department->name.'. They see the dates, places, team and their cost lines, not the memo.' : 'No '.$kind->office().' department is set in Reference data › Thresholds.') }}</span>
            </span>
        </label>
    @endforeach
    <div x-data x-show="$wire.instruct.length > 0" x-cloak>
        <x-ui.field label="Note to Finance and Procurement (optional)" for="ins-note" error="instructionNote"><textarea id="ins-note" wire:model="instructionNote" rows="2" maxlength="2000" class="{{ Ui::CONTROL }}" placeholder="e.g. Economy class; imprest to the team lead"></textarea></x-ui.field>
    </div>

    <div class="pt-2 border-t border-slate-100 space-y-2">
        <p class="text-[12px] font-semibold text-slate-700"><i class="fa-solid fa-share-from-square text-xs text-slate-400 mr-1"></i>Other departments</p>
        @foreach ($others as $i => $row)
            <div class="rounded-lg bg-slate-50 p-2 space-y-2" wire:key="other-ins-{{ $i }}">
                <div class="flex items-start gap-2">
                    <x-ui.field label="Department" for="oi-d-{{ $i }}" error="otherInstructions.{{ $i }}.department_id" class="flex-1">
                        <select id="oi-d-{{ $i }}" wire:model="otherInstructions.{{ $i }}.department_id" class="{{ Ui::CONTROL }} appearance-none pr-8"><option value="">Choose…</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
                    </x-ui.field>
                    <button type="button" wire:click="removeOtherInstruction({{ $i }})" class="{{ Ui::BTN_ICON_DANGER }} mt-6" aria-label="Remove this department"><i class="fa-solid fa-xmark text-xs"></i></button>
                </div>
                <x-ui.field label="What the department must do" for="oi-t-{{ $i }}" error="otherInstructions.{{ $i }}.instruction"><textarea id="oi-t-{{ $i }}" wire:model="otherInstructions.{{ $i }}.instruction" rows="2" maxlength="2000" class="{{ Ui::CONTROL }}" placeholder="e.g. Provide a Board vehicle and driver for the four days"></textarea></x-ui.field>
            </div>
        @endforeach
        <button type="button" wire:click="addOtherInstruction" class="{{ Ui::BTN_TINT }}"><i class="fa-solid fa-plus text-[9px]"></i>Instruct another department</button>
        <p class="text-[11px] text-slate-400">Its Head sees an instruction sheet with your instruction, the dates, places and team; not the memo or its costs.</p>
    </div>
</div>
