@use('App\Support\Ui')
@php $sel = Ui::CONTROL.' appearance-none pr-8'; @endphp
<x-ui.page icon="fa-id-badge" title="Staff list" subtitle="Participants are chosen from this list. Job grade drives the DSA rate." scroll="inner">
    <x-slot:pills><span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 text-[11px] font-semibold font-numeric">{{ $counts['active'] }} active</span>
        @if ($counts['no_grade'])<span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-amber-50 text-amber-700 text-[11px] font-semibold font-numeric">{{ $counts['no_grade'] }} without job grade</span>@endif
    </x-slot:pills>
    <x-slot:actions>
        <button type="button" wire:click="$set('showImport', true)" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-file-import text-xs"></i>Import HR list</button>
        <button type="button" wire:click="edit" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-plus text-xs"></i>Add officer</button>
    </x-slot:actions>
    <x-slot:filters>
        <div class="relative flex items-center flex-1 min-w-[12rem]">
            <i class="fa-solid fa-magnifying-glass absolute left-3 text-gray-400 text-xs pointer-events-none"></i>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name, PF number or email…" class="{{ Ui::CONTROL }} pl-9" aria-label="Search">
        </div>
        <select wire:model.live="department_id" class="{{ $sel }} shrink-0 sm:w-56" aria-label="Department"><option value="">All departments</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
        <select wire:model.live="state" class="{{ $sel }} shrink-0 sm:w-48" aria-label="Status"><option value="active">Active</option><option value="inactive">Inactive</option><option value="no_grade">Active, no job grade</option><option value="all">All</option></select>
    </x-slot:filters>

    <div class="flex-1 min-h-0 flex flex-col {{ Ui::CARD }}">
        @if ($staff->isEmpty())
            <x-ui.empty icon="fa-id-badge" title="No staff" :line="$search || $department_id ? 'No officer matches the filters.' : 'Import the HR staff list or add officers one by one.'" />
        @else
            <div class="flex-1 min-h-0 overflow-auto">
                <table class="w-full">
                    <thead class="sticky top-0 z-10 bg-white border-b border-slate-100"><tr>
                        <th class="{{ Ui::TH }}">PF number</th><th class="{{ Ui::TH }}">Name</th><th class="{{ Ui::TH }}">Designation</th><th class="{{ Ui::TH }}">Job grade</th><th class="{{ Ui::TH }}">Department</th><th class="{{ Ui::TH }}">Duty station</th><th class="{{ Ui::TH }} text-right">Activities</th><th class="{{ Ui::TH }}"></th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($staff as $s)
                            <tr class="hover:bg-slate-50/60 {{ $s->is_active ? '' : 'opacity-60' }}" wire:key="st-{{ $s->id }}">
                                <td class="{{ Ui::TD }} font-numeric">{{ $s->staff_number ?: '—' }}</td>
                                <td class="{{ Ui::TD }} font-medium text-slate-700">{{ $s->name }}@unless ($s->is_active)<x-ui.badge class="ml-1">Inactive</x-ui.badge>@endunless</td>
                                <td class="{{ Ui::TD }}">{{ $s->designation?->name ?? '—' }}</td>
                                <td class="{{ Ui::TD }} font-numeric">{!! $s->job_grade ? e($s->job_grade) : '<span class="text-amber-600">not set</span>' !!}</td>
                                <td class="{{ Ui::TD }}">{{ $s->department?->name ?? '—' }}</td>
                                <td class="{{ Ui::TD }}">{{ $s->office?->name ?? '—' }}</td>
                                <td class="{{ Ui::TD }} text-right font-numeric"><a href="{{ route('activities.index', ['staff_id' => $s->id]) }}" wire:navigate class="text-emerald-700 hover:underline">{{ $s->participations_count }}</a></td>
                                <td class="{{ Ui::TD }} text-right"><button type="button" wire:click="edit({{ $s->id }})" class="{{ Ui::BTN_ICON }}" aria-label="Edit {{ $s->name }}"><i class="fa-solid fa-pen text-xs"></i></button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($staff->hasPages())<div class="shrink-0 px-5 py-3 border-t border-slate-100 bg-slate-50">{{ $staff->links('livewire.custom-pagination') }}</div>@endif
        @endif
    </div>

    <x-ui.modal show="showForm" :open="$showForm" icon="fa-id-badge" :title="$editingId ? 'Edit officer' : 'Add officer'" subtitle="Staff are never deleted; mark them inactive when they leave.">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <x-ui.field label="PF number" for="f-pf" error="staff_number" required><input id="f-pf" type="text" wire:model="staff_number" maxlength="30" class="{{ Ui::CONTROL }} font-numeric"></x-ui.field>
            <x-ui.field label="Name" for="f-n" error="name" required><input id="f-n" type="text" wire:model="name" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
            <x-ui.field label="Department" for="f-d" error="form_department_id" required><select id="f-d" wire:model="form_department_id" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></x-ui.field>
            <x-ui.field label="Designation" for="f-des" error="designation_id"><select id="f-des" wire:model="designation_id" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($designations as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></x-ui.field>
            <x-ui.field label="Job grade" for="f-g" error="job_grade" hint="Must match a grade in the DSA rate table."><input id="f-g" type="text" wire:model="job_grade" maxlength="20" class="{{ Ui::CONTROL }}"></x-ui.field>
            <x-ui.field label="Duty station" for="f-o" error="office_id"><select id="f-o" wire:model="office_id" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($offices as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach</select></x-ui.field>
            <x-ui.field label="Email" for="f-e" error="email"><input id="f-e" type="email" wire:model="email" class="{{ Ui::CONTROL }}"></x-ui.field>
            <x-ui.field label="Phone" for="f-p" error="phone"><input id="f-p" type="text" wire:model="phone" maxlength="30" class="{{ Ui::CONTROL }}"></x-ui.field>
            <label class="flex items-center gap-2 text-[13px] text-slate-600 sm:col-span-2"><input type="checkbox" wire:model="is_active" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">Active</label>
        </div>
        <x-slot:footer>
            <button type="button" wire:click="$set('showForm', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-floppy-disk text-xs"></i>Save</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showImport" :open="$showImport" icon="fa-file-import" title="Import the HR staff list" subtitle="Existing officers are matched by PF number and updated. Nothing is deleted.">
        <p class="text-[13px] text-slate-600">Columns (first row): <span class="font-numeric text-slate-800">{{ implode(', ', $columns) }}</span>. Only PF Number and Name are required.</p>
        <x-ui.field label="File" for="imp-f" error="file" required hint="Excel or CSV, up to 2 MB."><input id="imp-f" type="file" wire:model="file" accept=".xlsx,.xls,.csv" class="block w-full text-[13px] text-slate-600 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:font-semibold"></x-ui.field>
        @if ($importErrors)
            <div class="rounded-lg bg-red-50 border border-red-200 p-3 max-h-40 overflow-y-auto"><p class="text-[13px] font-semibold text-red-700 mb-1">Nothing was imported. Fix these rows:</p>@foreach ($importErrors as $e)<p class="text-[12px] text-red-700">{{ $e }}</p>@endforeach</div>
        @endif
        <x-slot:footer>
            <button type="button" wire:click="$set('showImport', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="import" wire:loading.attr="disabled" wire:target="import,file" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-upload text-xs"></i><span wire:loading.remove wire:target="import">Import</span><span wire:loading wire:target="import">Importing…</span></button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.page>
