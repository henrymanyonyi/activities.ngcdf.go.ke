@use('App\Support\Ui')
@use('App\Support\Money')
@use('App\Enums\DecisionType')

<x-ui.page icon="fa-gavel" title="Decision queue" subtitle="Activities submitted for the CEO's decision, soonest first.">
    <x-slot:pills>
        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-amber-50 text-amber-700 text-[11px] font-semibold font-numeric">{{ $items->count() }} awaiting</span>
    </x-slot:pills>

    @forelse ($items as $item)
        @php $a = $item['activity']; @endphp
        <article class="{{ Ui::CARD }}" wire:key="q-{{ $a->id }}">
            <div class="px-5 py-4 flex flex-col lg:flex-row lg:items-start gap-4">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 flex-wrap">
                        <a href="{{ route('activities.show', $a) }}" wire:navigate class="text-base font-semibold text-slate-800 hover:text-emerald-700">{{ $a->title }}</a>
                        @if ($a->is_late_notice)<x-ui.badge classes="bg-amber-50 text-amber-700" icon="fa-clock">Late notice</x-ui.badge>@endif
                        @if ($a->is_retrospective)<x-ui.badge classes="bg-red-50 text-red-700" icon="fa-backward">Retrospective</x-ui.badge>@endif
                        @if ($item['returnedAmendment'])<x-ui.badge classes="bg-orange-50 text-orange-700" icon="fa-pen">Amended after approval</x-ui.badge>@endif
                    </div>
                    <p class="text-[12px] text-slate-500 mt-0.5 font-numeric">{{ $a->reference }} · {{ $a->organisingDepartment?->name }} · {{ $a->type?->name ?? 'No type' }} · memo {{ $a->source_reference ?: '—' }}</p>
                    <p class="text-[13px] text-slate-600 mt-2 line-clamp-2">{{ $a->purpose }}</p>
                    @if ($item['returnedAmendment'])
                        <p class="text-[12px] text-orange-700 mt-1"><i class="fa-solid fa-circle-info mr-1"></i>{{ $item['returnedAmendment']->summary }}. Reason: {{ $item['returnedAmendment']->reason }}</p>
                    @endif

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2.5 mt-3">
                        <div class="rounded-lg border border-slate-200 px-3 py-2"><p class="{{ Ui::MICRO }}">Dates</p><p class="text-[13px] font-bold font-numeric">{{ Ui::dateRange($a->start_date, $a->end_date) }}</p><p class="text-[11px] text-slate-400">{{ $a->days }} days · {{ $a->nights }} nights</p></div>
                        <div class="rounded-lg border border-slate-200 px-3 py-2"><p class="{{ Ui::MICRO }}">Where</p><p class="text-[13px] font-bold line-clamp-2">{{ $a->locationSummary() ?: '—' }}</p></div>
                        <div class="rounded-lg border border-slate-200 px-3 py-2"><p class="{{ Ui::MICRO }}">Team</p><p class="text-[13px] font-bold font-numeric">{{ $a->staff_count }} officers{{ $a->external_count ? ' +'.$a->external_count : '' }}</p><p class="text-[11px] text-slate-400 line-clamp-1">{{ $a->participants->where('is_external', false)->map(fn ($p) => $p->staff?->name)->take(3)->implode(', ') }}{{ $a->staff_count > 3 ? '…' : '' }}</p></div>
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2"><p class="{{ Ui::MICRO }}">Planned cost</p><p class="text-[13px] font-bold font-numeric">{{ Money::format(Money::toCents($a->estimated_total)) }}</p><p class="text-[11px] text-slate-500">{{ $a->costPerHead() !== null ? 'KES '.Money::format($a->costPerHead()).' per head' : '' }}</p></div>
                    </div>

                    @if (count($item['conflicts']) || $item['overLimit']->isNotEmpty())
                        <div class="mt-3 space-y-1">
                            @foreach ($item['conflicts'] as $pid => $others)
                                @php $p = $a->participants->firstWhere('id', $pid); @endphp
                                <p class="text-[12px] text-red-600"><i class="fa-solid fa-triangle-exclamation mr-1"></i>{{ $p?->displayName() }} is also on {{ $others->pluck('reference')->implode(', ') }}@if ($p?->conflict_reason)<span class="text-slate-500">: {{ $p->conflict_reason }}</span>@endif</p>
                            @endforeach
                            @foreach ($item['overLimit'] as $p)
                                <p class="text-[12px] text-amber-700"><i class="fa-solid fa-flag mr-1"></i>{{ $p->displayName() }} would exceed the field-day limit.</p>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="flex lg:flex-col gap-2 shrink-0">
                    @can('activities.decide')
                        <button type="button" wire:click="open({{ $a->id }}, 'approved')" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-check text-xs"></i>Approve</button>
                        <button type="button" wire:click="open({{ $a->id }}, 'returned')" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-rotate-left text-xs"></i>Return</button>
                        <button type="button" wire:click="open({{ $a->id }}, 'declined')" class="{{ Ui::BTN_NEUTRAL }} hover:!text-red-600"><i class="fa-solid fa-xmark text-xs"></i>Decline</button>
                    @else
                        <a href="{{ route('activities.show', $a) }}" wire:navigate class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-eye text-xs"></i>Open</a>
                    @endcan
                </div>
            </div>
        </article>
    @empty
        <div class="{{ Ui::CARD }} flex"><x-ui.empty icon="fa-check-double" title="Nothing awaiting decision" line="Activities appear here when the Chief of Staff or Assistant submits them." /></div>
    @endforelse

    <x-ui.modal show="showDecide" :open="$showDecide" :size="$decision === 'approved' ? 'slim' : 'medium'" :tone="$decision === 'declined' ? 'red' : 'emerald'" icon="fa-gavel"
        :title="DecisionType::from($decision)->actionLabel().($selected ? ': '.$selected->reference : '')" :subtitle="$selected?->title">
        <x-ui.field :label="$decision === 'approved' ? 'Comment (optional)' : 'Directive / comment'" for="q-comment" error="comment" :required="$decision !== 'approved'">
            <textarea id="q-comment" wire:model="comment" rows="3" class="{{ Ui::CONTROL }}"></textarea>
        </x-ui.field>
        <x-slot:footer>
            <button type="button" wire:click="$set('showDecide', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="decide" wire:loading.attr="disabled" wire:target="decide" class="{{ $decision === 'declined' ? Ui::BTN_DANGER : Ui::BTN_PRIMARY }}"><i class="fa-solid fa-gavel text-xs"></i>{{ DecisionType::from($decision)->actionLabel() }}</button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.page>
