@use('App\Support\Ui')
@use('App\Support\Money')
@use('App\Enums\InstructionStatus')
@php
    $total = $lines->sum(fn ($c) => Money::toCents($c->estimated_amount));
    $staffCount = $a->participants->where('is_external', false)->count();
@endphp
@php $written = $i->kind->needsWrittenInstruction(); @endphp
<x-ui.page :icon="$i->kind->icon()" :title="$written ? 'Instruction from the CEO' : $i->kind->label()" :subtitle="$a->reference.' · '.$a->title">
    <x-slot:pills><x-ui.badge :classes="$i->status->badgeClasses()">{{ $i->status->label() }}</x-ui.badge></x-slot:pills>
    <x-slot:actions>
        @if ($acts)
            <a href="{{ route('instructions.index') }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-arrow-left text-xs"></i>Instructions</a>
        @else
            <a href="{{ route('activities.show', $a) }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-arrow-left text-xs"></i>Activity</a>
        @endif
        @if ($a->status->isApprovedOrLater())
            <a href="{{ route('memos.document', $a) }}" target="_blank" rel="noopener" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-file-pdf text-xs text-red-600"></i>Approved memo</a>
        @endif
        @if (! $acts)
        @elseif ($i->status === InstructionStatus::Sent)
            <button type="button" wire:click="acknowledge" wire:loading.attr="disabled" wire:target="acknowledge" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-handshake text-xs"></i>Acknowledge</button>
        @endif
        @if ($acts && $i->status->isOpen())
            <button type="button" wire:click="$set('showComplete', true)" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-circle-check text-xs"></i>Mark as done</button>
        @endif
    </x-slot:actions>

    <x-ui.card title="The CEO's instruction" icon="fa-gavel" padded>
        @if ($written)
            <p class="text-[13px] text-slate-700">The CEO has approved this activity and instructs {{ $i->department?->name ?? 'your department' }} to:</p>
            <p class="mt-2 text-[14px] font-semibold text-slate-800 whitespace-pre-line border-l-2 border-emerald-500 pl-3">{{ $i->note }}</p>
        @else
            <p class="text-[13px] text-slate-700">The CEO has approved this activity and instructs {{ $i->department?->name ?? $i->kind->office() }} to <strong>{{ mb_strtolower($i->kind->label()) }}</strong>.</p>
            @if ($i->note)<p class="mt-2 text-[13px] text-slate-700 whitespace-pre-line border-l-2 border-emerald-500 pl-3">{{ $i->note }}</p>@endif
        @endif
        <dl class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-3 text-[12px]">
            <div><dt class="text-slate-400">Sent</dt><dd class="text-slate-700 font-numeric">{{ Ui::date($i->sent_at) }} by {{ $i->sender?->name ?? 'the CEO' }}</dd></div>
            <div><dt class="text-slate-400">Acknowledged</dt><dd class="text-slate-700 font-numeric">{{ $i->acknowledged_at ? Ui::date($i->acknowledged_at).' by '.$i->acknowledger?->name : 'Not yet' }}</dd></div>
            <div><dt class="text-slate-400">Done</dt><dd class="text-slate-700 font-numeric">{{ $i->completed_at ? Ui::date($i->completed_at).' by '.$i->completer?->name.' · '.$i->kind->referenceLabel().' '.$i->completion_reference : 'Not yet' }}</dd></div>
        </dl>
        @if ($i->completion_note)<p class="mt-2 text-[12px] text-slate-500 whitespace-pre-line">{{ $i->completion_note }}</p>@endif
    </x-ui.card>

    <x-ui.card title="Activity" icon="fa-calendar-days" padded>
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-[13px]">
            <div><dt class="text-[11px] text-slate-400">Reference</dt><dd class="font-numeric text-slate-700">{{ $a->reference }}</dd></div>
            <div><dt class="text-[11px] text-slate-400">Organising department</dt><dd class="text-slate-700">{{ $a->organisingDepartment?->name ?? 'Office of the CEO' }}</dd></div>
            <div><dt class="text-[11px] text-slate-400">Dates</dt><dd class="font-numeric text-slate-700">{{ Ui::dateRange($a->start_date, $a->end_date) }} · {{ $a->days }} {{ Str::plural('day', $a->days) }}, {{ $a->nights }} {{ Str::plural('night', $a->nights) }}</dd></div>
            <div><dt class="text-[11px] text-slate-400">Status</dt><dd><x-ui.status :status="$a->status" /></dd></div>
            <div class="sm:col-span-2"><dt class="text-[11px] text-slate-400">Places</dt><dd class="text-slate-700">{{ $a->locations->map->label()->implode('; ') ?: 'Not stated' }}</dd></div>
        </dl>
    </x-ui.card>

    <x-ui.card title="Team" :count="$a->participants->count()" icon="fa-users">
        <table class="w-full">
            <thead class="border-b border-slate-100"><tr><th class="{{ Ui::TH }}">Name</th><th class="{{ Ui::TH }}">PF number</th><th class="{{ Ui::TH }}">Scale</th><th class="{{ Ui::TH }}">Role</th><th class="{{ Ui::TH }}">Days on the activity</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($a->participants as $p)
                    <tr wire:key="p-{{ $p->id }}">
                        <td class="{{ Ui::TD }} text-slate-700">{{ $p->displayName() }}@if ($p->is_external)<span class="text-[11px] text-slate-400"> · {{ $p->external_organisation ?: 'External' }}</span>@endif</td>
                        <td class="{{ Ui::TD }} font-numeric">{{ $p->is_external ? '' : ($p->staff?->staff_number ?: '—') }}</td>
                        <td class="{{ Ui::TD }} font-numeric">{{ $p->is_external ? '' : ($p->job_grade ?? $p->staff?->job_grade ?? '—') }}</td>
                        <td class="{{ Ui::TD }}">{{ $p->role?->label() }}</td>
                        <td class="{{ Ui::TD }} font-numeric">{{ Ui::dateRange($p->start_date ?? $a->start_date, $p->end_date ?? $a->end_date) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="px-5 py-3 text-[11px] text-slate-400 border-t border-slate-100">{{ $staffCount }} {{ Str::plural('officer', $staffCount) }} of the Board{{ $a->participants->count() > $staffCount ? ', and '.($a->participants->count() - $staffCount).' from outside' : '' }}.</p>
    </x-ui.card>

    @if ($i->kind->costCodes() !== [])
    <x-ui.card :title="$i->kind->costCodes() === null ? 'Planned costs' : 'Travel and transport costs'" icon="fa-coins">
        @if ($lines->isEmpty())
            <x-ui.empty icon="fa-coins" title="No costs of this kind" line="The approved budget has no lines for {{ mb_strtolower($i->kind->office()) }} to act on. Ask the Office of the CEO if you expected some." />
        @else
            <table class="w-full">
                <thead class="border-b border-slate-100"><tr><th class="{{ Ui::TH }}">Cost</th><th class="{{ Ui::TH }}">For</th><th class="{{ Ui::TH }}">Details</th><th class="{{ Ui::TH }} text-right">Planned (KES)</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($lines as $c)
                        <tr wire:key="c-{{ $c->id }}">
                            <td class="{{ Ui::TD }} text-slate-700">{{ $c->category?->name }}</td>
                            <td class="{{ Ui::TD }}">{{ $c->participant?->displayName() ?? 'Whole activity' }}</td>
                            <td class="{{ Ui::TD }}">{{ $c->description }}@if ($c->travel_mode)<span class="text-slate-400"> · {{ $c->travel_mode->label() }}</span>@endif</td>
                            <td class="{{ Ui::TD }} text-right"><x-ui.money :value="$c->estimated_amount" /></td>
                        </tr>
                    @endforeach
                    <tr class="bg-slate-50 font-semibold"><td class="{{ Ui::TD }}" colspan="3">Total</td><td class="{{ Ui::TD }} text-right font-numeric">{{ Money::format($total) }}</td></tr>
                </tbody>
            </table>
        @endif
    </x-ui.card>
    @endif

    <x-ui.modal show="showComplete" :open="$showComplete" size="slim" icon="fa-circle-check" title="Mark as done" :subtitle="($written ? 'Instruction from the CEO' : $i->kind->label()).' · '.$a->reference">
        <x-ui.field :label="$i->kind->referenceLabel()" for="ic-ref" error="reference" required><input id="ic-ref" type="text" wire:model="reference" maxlength="100" class="{{ Ui::CONTROL }} font-numeric"></x-ui.field>
        <x-ui.field label="Note (optional)" for="ic-note" error="note"><textarea id="ic-note" wire:model="note" rows="3" maxlength="2000" class="{{ Ui::CONTROL }}"></textarea></x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showComplete', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="complete" wire:loading.attr="disabled" wire:target="complete" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-circle-check text-xs"></i>Mark as done</button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.page>
