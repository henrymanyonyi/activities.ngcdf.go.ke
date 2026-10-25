@use('App\Support\Ui')
<x-ui.page icon="fa-clipboard-check" title="Instructions" subtitle="The CEO's instructions to your department on approved activities: process the imprest, procure the travel tickets, or whatever the CEO asks your department to do. Open one to see what is needed.">
    <x-slot:pills><span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-amber-50 text-amber-700 text-[11px] font-semibold font-numeric">{{ $openCount }} open</span></x-slot:pills>
    <x-slot:filters>
        <div class="flex gap-2">
            @foreach (['open' => 'Open', 'completed' => 'Completed'] as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')" class="px-3 py-1.5 rounded-lg text-[13px] font-semibold {{ $tab === $key ? 'bg-emerald-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' }}">{{ $label }}</button>
            @endforeach
        </div>
    </x-slot:filters>

    <div class="flex-1 min-h-0 flex flex-col {{ Ui::CARD }}">
        @if ($instructions->isEmpty())
            <x-ui.empty icon="fa-clipboard-check" :title="$tab === 'open' ? 'No open instructions' : 'Nothing completed yet'" line="When the CEO approves an activity and instructs your department, it appears here." />
        @else
            <div class="flex-1 min-h-0 overflow-auto">
                <table class="w-full">
                    <thead class="sticky top-0 z-10 bg-white border-b border-slate-100"><tr>
                        <th class="{{ Ui::TH }}">Instruction</th><th class="{{ Ui::TH }}">Activity</th><th class="{{ Ui::TH }}">Department</th><th class="{{ Ui::TH }}">Dates</th><th class="{{ Ui::TH }}">{{ $tab === 'open' ? 'Sent' : 'Completed' }}</th><th class="{{ Ui::TH }}">Status</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($instructions as $i)
                            <tr class="hover:bg-slate-50/60" wire:key="ins-{{ $i->id }}">
                                <td class="{{ Ui::TD }}"><a href="{{ route('instructions.show', $i) }}" wire:navigate class="font-semibold text-emerald-700 hover:underline"><i class="fa-solid {{ $i->kind->icon() }} text-xs mr-1.5"></i>{{ $i->kind->needsWrittenInstruction() ? Str::limit((string) $i->note, 60) : $i->kind->label() }}</a></td>
                                <td class="{{ Ui::TD }}"><span class="font-numeric text-slate-400">{{ $i->activity->reference }}</span> <span class="text-slate-700">{{ $i->activity->title }}</span></td>
                                <td class="{{ Ui::TD }}">{{ $i->activity->organisingDepartment?->name ?? 'Office of the CEO' }}</td>
                                <td class="{{ Ui::TD }} font-numeric whitespace-nowrap">{{ Ui::dateRange($i->activity->start_date, $i->activity->end_date) }}</td>
                                <td class="{{ Ui::TD }} font-numeric whitespace-nowrap">{{ Ui::date($tab === 'open' ? $i->sent_at : $i->completed_at) }}</td>
                                <td class="{{ Ui::TD }}"><x-ui.badge :classes="$i->status->badgeClasses()">{{ $i->status->label() }}</x-ui.badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($instructions->hasPages())<div class="shrink-0 px-5 py-3 border-t border-slate-100 bg-slate-50">{{ $instructions->links('livewire.custom-pagination') }}</div>@endif
        @endif
    </div>
</x-ui.page>
