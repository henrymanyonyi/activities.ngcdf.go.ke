@use('App\Support\Ui')
<x-ui.page icon="fa-person-walking-luggage" title="In the field today" :subtitle="today()->format('l, d M Y').' · officers on an approved or in-progress activity'" scroll="inner">
    <x-slot:pills><span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-blue-50 text-blue-700 text-[11px] font-semibold font-numeric">{{ $people->unique('staff_id')->count() }} officers</span></x-slot:pills>
    <x-slot:filters>
        <div class="relative flex items-center flex-1 min-w-[12rem]">
            <i class="fa-solid fa-magnifying-glass absolute left-3 text-gray-400 text-xs pointer-events-none"></i>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search officer, department or activity…" class="{{ Ui::CONTROL }} pl-9" aria-label="Search">
        </div>
    </x-slot:filters>

    <div class="flex-1 min-h-0 flex flex-col {{ Ui::CARD }}">
        @if ($people->isEmpty())
            <x-ui.empty icon="fa-house" title="Nobody is in the field today" :line="$search ? 'No officer matches your search.' : null" />
        @else
            <div class="flex-1 min-h-0 overflow-auto">
                <table class="w-full">
                    <thead class="sticky top-0 z-10 bg-white border-b border-slate-100"><tr>
                        <th class="{{ Ui::TH }}">Officer</th><th class="{{ Ui::TH }}">Department</th><th class="{{ Ui::TH }}">Activity</th><th class="{{ Ui::TH }}">Location</th><th class="{{ Ui::TH }}">Returns</th><th class="{{ Ui::TH }}">Status</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($people as $p)
                            <tr class="hover:bg-slate-50/60" wire:key="f-{{ $p->id }}">
                                <td class="{{ Ui::TD }}"><p class="font-medium text-slate-700">{{ $p->staff?->name }}</p><p class="text-[11px] text-slate-400 font-numeric">{{ $p->staff?->staff_number }} · {{ $p->role->label() }}</p></td>
                                <td class="{{ Ui::TD }}">{{ $p->staff?->department?->name }}</td>
                                <td class="{{ Ui::TD }}"><a href="{{ route('activities.show', $p->activity) }}" wire:navigate class="text-emerald-700 hover:underline">{{ $p->activity->title }}</a></td>
                                <td class="{{ Ui::TD }}">{{ $p->activity->locationSummary() ?: '—' }}</td>
                                <td class="{{ Ui::TD }} font-numeric whitespace-nowrap">{{ Ui::date($p->activity->end_date) }}</td>
                                <td class="{{ Ui::TD }}"><x-ui.status :status="$p->activity->status" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-ui.page>
