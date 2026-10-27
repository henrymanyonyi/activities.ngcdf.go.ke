@use('App\Enums\EmploymentType')
@use('App\Support\Ui')
@php $sel = Ui::CONTROL.' appearance-none pr-8'; @endphp
<x-ui.page icon="fa-id-badge" title="Staff list" :subtitle="$manages ? 'Participants are chosen from this list. Job grade drives the DSA rate.' : 'Officers you can add to a memo\'s team. If someone is missing, add them; you can correct the officers you added.'" scroll="inner">
    <x-slot:pills><span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 text-[11px] font-semibold font-numeric">{{ $counts['active'] }} active</span>
        @if ($counts['no_grade'])<span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-amber-50 text-amber-700 text-[11px] font-semibold font-numeric">{{ $counts['no_grade'] }} without job grade</span>@endif
    </x-slot:pills>
    <x-slot:actions>
        @if ($canAdd)
            <button type="button" wire:click="$set('showImport', true)" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-file-import text-xs"></i>{{ $manages ? 'Import HR list' : 'Import staff list' }}</button>
            <button type="button" wire:click="edit" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-plus text-xs"></i>Add officer</button>
        @endif
    </x-slot:actions>

    @if ($oneTimePassword)
        <div class="px-4 py-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm" role="alert">
            <p class="font-semibold"><i class="fa-solid fa-key mr-1"></i>One-time password for {{ $oneTimeFor }}</p>
            <p class="font-numeric text-base mt-1 select-all">{{ $oneTimePassword }}</p>
            <p class="text-[12px] mt-1">A link to set their own password has also been emailed to them. This password is shown once: share it privately only if the email does not reach them.</p>
            <button type="button" wire:click="$set('oneTimePassword', null)" class="mt-2 {{ Ui::BTN_TINT }}">I have shared it</button>
        </div>
    @endif
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
                        <th class="{{ Ui::TH }}">PF number</th><th class="{{ Ui::TH }}">Name</th><th class="{{ Ui::TH }}">Designation</th><th class="{{ Ui::TH }}">Job grade</th><th class="{{ Ui::TH }}">Department</th><th class="{{ Ui::TH }}">Duty station</th>@if ($manages)<th class="{{ Ui::TH }} text-right">Activities</th>@endif<th class="{{ Ui::TH }}"></th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($staff as $s)
                            <tr class="hover:bg-slate-50/60 {{ $s->is_active ? '' : 'opacity-60' }}" wire:key="st-{{ $s->id }}">
                                <td class="{{ Ui::TD }} font-numeric">{{ $s->staff_number ?: '—' }}</td>
                                <td class="{{ Ui::TD }} font-medium text-slate-700">{{ $s->name }}@unless ($s->employment_type === EmploymentType::Permanent)<x-ui.badge class="ml-1">{{ $s->employment_type->label() }}</x-ui.badge>@endunless@if (in_array($s->id, $managerIds, true))<x-ui.badge class="ml-1" classes="bg-emerald-50 text-emerald-700">Manager</x-ui.badge>@endif@unless ($s->is_active)<x-ui.badge class="ml-1">Inactive</x-ui.badge>@endunless</td>
                                <td class="{{ Ui::TD }}">{{ $s->designation?->name ?? '—' }}</td>
                                <td class="{{ Ui::TD }} font-numeric">{!! $s->job_grade ? e($s->job_grade) : '<span class="text-amber-600">not set</span>' !!}</td>
                                <td class="{{ Ui::TD }}">{{ $s->department?->name ?? '—' }}</td>
                                <td class="{{ Ui::TD }}">{{ $s->office?->name ?? '—' }}</td>
                                @if ($manages)<td class="{{ Ui::TD }} text-right font-numeric">@can('activities.view')<a href="{{ route('activities.index', ['staff_id' => $s->id]) }}" wire:navigate class="text-emerald-700 hover:underline">{{ $s->participations_count }}</a>@else{{ $s->participations_count }}@endcan</td>@endif
                                <td class="{{ Ui::TD }} text-right whitespace-nowrap">
                                    @if ($canReassign)<button type="button" wire:click="openPosition({{ $s->id }})" class="{{ Ui::BTN_ICON }}" aria-label="Change {{ $s->name }}'s position or access" title="Promotion, transfer or access"><i class="fa-solid fa-user-tie text-xs"></i></button>@endif
                                    @if ($records->mayEdit(auth()->user(), $s))<button type="button" wire:click="edit({{ $s->id }})" class="{{ Ui::BTN_ICON }}" aria-label="Edit {{ $s->name }}"><i class="fa-solid fa-pen text-xs"></i></button>@endif
                                </td>
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
            <x-ui.field label="Employment type" for="f-t" error="employment_type" required><select id="f-t" wire:model.live="employment_type" class="{{ $sel }}">@foreach ($employmentTypes as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach</select></x-ui.field>
            <x-ui.field label="PF number" for="f-pf" error="staff_number" :required="$employment_type === 'permanent'" :hint="$employment_type === 'permanent' ? null : 'Leave blank if HR has not issued one.'"><input id="f-pf" type="text" wire:model="staff_number" maxlength="30" class="{{ Ui::CONTROL }} font-numeric"></x-ui.field>
            <x-ui.field label="Name" for="f-n" error="name" required><input id="f-n" type="text" wire:model="name" maxlength="255" class="{{ Ui::CONTROL }}"></x-ui.field>
            <x-ui.field label="Department" for="f-d" error="form_department_id" required><select id="f-d" wire:model="form_department_id" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></x-ui.field>
            <x-ui.field label="Designation" for="f-des" error="designation_id"><select id="f-des" wire:model="designation_id" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($designations as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></x-ui.field>
            <x-ui.field label="Job grade" for="f-g" error="job_grade" hint="The scale from the HR register, 1 to 10; it sets the DSA rate. Casuals and attachees left blank get INTERN."><input id="f-g" type="text" wire:model="job_grade" maxlength="20" class="{{ Ui::CONTROL }}"></x-ui.field>
            <x-ui.field label="Duty station" for="f-o" error="office_id"><select id="f-o" wire:model="office_id" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($offices as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach</select></x-ui.field>
            <x-ui.field label="Email" for="f-e" error="email"><input id="f-e" type="email" wire:model="email" class="{{ Ui::CONTROL }}"></x-ui.field>
            <x-ui.field label="Mobile number" for="f-p" error="phone" hint="An account made from this officer receives FAPM's action-needed SMS here."><input id="f-p" type="tel" wire:model="phone" maxlength="30" class="{{ Ui::CONTROL }}" placeholder="e.g. 0712 345 678"></x-ui.field>
            <x-ui.field label="Contract end date" for="f-ce" error="contract_ends_on"><input id="f-ce" type="date" wire:model="contract_ends_on" class="{{ Ui::CONTROL }}"></x-ui.field>
            @if ($manages)
                <label class="flex items-center gap-2 text-[13px] text-slate-600 sm:col-span-2"><input type="checkbox" wire:model="is_active" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">Active</label>
            @else
                <p class="text-[11px] text-slate-400 sm:col-span-2">Check the PF number and job grade with HR: the job grade sets the officer's allowance (DSA) rate.</p>
            @endif
        </div>
        <x-slot:footer>
            <button type="button" wire:click="$set('showForm', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-floppy-disk text-xs"></i>Save</button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal show="showImport" :open="$showImport" icon="fa-file-import" :title="$manages ? 'Import the HR staff list' : 'Import a staff list'" :subtitle="$manages ? 'Existing officers are matched by PF number and updated. Nothing is deleted.' : 'Officers not yet on the list are added. Officers you added before are updated; everyone else is left as they are. Nothing is deleted.'">
        <p class="text-[13px] text-slate-600">Columns (first row): <span class="font-numeric text-slate-800">{{ implode(', ', $columns) }}</span>. Name is required; PF Number is required for permanent staff. Employment Type is Permanent, Casual or Attachee (blank means Permanent). Job Grade is the scale from the HR register (1 to 10), or INTERN for casuals and attachees (a casual or attachee left blank gets INTERN). Department and Duty Station must match the lists in the system. Use <span class="font-semibold">Check file</span> to see what the import would do before saving anything.</p>
        <x-ui.field label="File" for="imp-f" error="file" required hint="Excel or CSV, up to 2 MB."><input id="imp-f" type="file" wire:model="file" accept=".xlsx,.xls,.csv" class="block w-full text-[13px] text-slate-600 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:font-semibold"></x-ui.field>
        @if ($importCheck)
            <div class="rounded-lg bg-emerald-50 border border-emerald-200 p-3"><p class="text-[13px] text-emerald-800">{{ $importCheck }}</p></div>
        @endif
        @if ($importErrors)
            <div class="rounded-lg bg-red-50 border border-red-200 p-3 max-h-40 overflow-y-auto"><p class="text-[13px] font-semibold text-red-700 mb-1">Nothing was imported. Fix these rows:</p>@foreach ($importErrors as $e)<p class="text-[12px] text-red-700">{{ $e }}</p>@endforeach</div>
        @endif
        <x-slot:footer>
            <button type="button" wire:click="$set('showImport', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
            <button type="button" wire:click="checkImport" wire:loading.attr="disabled" wire:target="checkImport,import,file" class="{{ Ui::BTN_NEUTRAL }}"><i class="fa-solid fa-list-check text-xs"></i><span wire:loading.remove wire:target="checkImport">Check file</span><span wire:loading wire:target="checkImport">Checking…</span></button>
            <button type="button" wire:click="import" wire:loading.attr="disabled" wire:target="checkImport,import,file" class="{{ Ui::BTN_PRIMARY }}"><i class="fa-solid fa-upload text-xs"></i><span wire:loading.remove wire:target="import">Import</span><span wire:loading wire:target="import">Importing…</span></button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Promotion, transfer or change of system access (App\Services\StaffChanges) --}}
    @if ($positionStaff)
        <x-ui.modal show="showPosition" :open="$showPosition" icon="fa-user-tie" :title="'Change '.$positionStaff->name.'\'s position or access'" subtitle="The department and designation are the officer's HR position; the system access is what they can do in FAPM. Each change is recorded with its effective date and reason.">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 rounded-lg bg-slate-50 border border-slate-200 px-3 py-2.5 text-[12px]">
                <div><p class="{{ Ui::MICRO }}">PF number</p><p class="font-numeric text-slate-700">{{ $positionStaff->staff_number ?: 'None' }}</p></div>
                <div><p class="{{ Ui::MICRO }}">Department now</p><p class="text-slate-700">{{ $positionStaff->department?->name ?? 'None' }}</p></div>
                <div><p class="{{ Ui::MICRO }}">Designation now</p><p class="text-slate-700">{{ $positionStaff->designation?->name ?? 'None' }}</p></div>
                <div><p class="{{ Ui::MICRO }}">System access now</p><p class="text-slate-700">@if ($positionAccount?->is_active){{ $positionAccount->roleLabel() }}@if ($positionAccount->department) · {{ $positionAccount->department->name }}@endif @elseif ($positionAccount)Account deactivated @else No account @endif</p></div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <x-ui.field label="Department" for="pc-d" error="posDepartment" required>
                    <select id="pc-d" wire:model.live="posDepartment" class="{{ $sel }}"><option value="">Choose…</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
                </x-ui.field>
                <x-ui.field label="Designation" for="pc-g" error="posDesignation">
                    <select id="pc-g" wire:model="posDesignation" class="{{ $sel }}"><option value="">None</option>@foreach ($designations as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
                </x-ui.field>
            </div>
            <x-ui.field label="System access" for="pc-a" error="posAccess" required hint="A designation never gives access by itself. A new account uses the email and mobile number on the staff list.">
                <select id="pc-a" wire:model.live="posAccess" class="{{ $sel }}">@foreach ($accessOptions as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
            </x-ui.field>
            @if ($currentHead)
                <label class="flex items-start gap-2 px-3 py-2 rounded-lg bg-orange-50 border border-orange-200 text-orange-800 text-[13px]">
                    <input type="checkbox" wire:model="posReplaceHead" class="mt-0.5 rounded border-orange-300 text-orange-600 focus:ring-orange-500">
                    <span>Replace the current Head, <strong>{{ $currentHead->name }}</strong>. Their account is deactivated and they are signed out; the change is recorded against them too.</span>
                </label>
            @endif
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <x-ui.field label="Effective date" for="pc-e" error="posEffective" required><input id="pc-e" type="date" wire:model="posEffective" class="{{ Ui::CONTROL }}"></x-ui.field>
                <x-ui.field label="Administrative reference" for="pc-r" error="posReference" class="sm:col-span-2"><input id="pc-r" type="text" wire:model="posReference" maxlength="100" class="{{ Ui::CONTROL }}" placeholder="e.g. HR/PROM/12/2026"></x-ui.field>
            </div>
            <x-ui.field label="Reason" for="pc-why" error="posReason" required><textarea id="pc-why" wire:model="posReason" rows="2" maxlength="2000" class="{{ Ui::CONTROL }}" placeholder="e.g. Promoted to Head of ICT by the Board, letter of 20 October 2026"></textarea></x-ui.field>

            @if ($positionStaff->positionChanges->isNotEmpty())
                <div>
                    <p class="{{ Ui::MICRO }} mb-1">History</p>
                    <ol class="divide-y divide-slate-100 border border-slate-100 rounded-lg max-h-40 overflow-y-auto">
                        @foreach ($positionStaff->positionChanges as $c)
                            <li class="px-3 py-2 text-[12px] text-slate-600">
                                <span class="font-semibold text-slate-700">From {{ $c->effective_on->format('d M Y') }}:</span>
                                {{ $c->toDepartment?->name ?? 'No department' }}@if ($c->toDesignation) · {{ $c->toDesignation->name }}@endif · {{ \App\Models\StaffChange::roleLabel($c->to_role) }}
                                <span class="text-slate-400">(was {{ $c->fromDepartment?->name ?? 'none' }}@if ($c->fromDesignation), {{ $c->fromDesignation->name }}@endif, {{ \App\Models\StaffChange::roleLabel($c->from_role) }})</span>
                                <span class="block text-slate-500">{{ $c->reason }}@if ($c->reference) · {{ $c->reference }}@endif · recorded by {{ $c->changer?->name ?? 'unknown' }} on {{ $c->created_at->format('d M Y H:i') }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endif

            <x-slot:footer>
                <button type="button" wire:click="$set('showPosition', false)" class="{{ Ui::BTN_NEUTRAL }}">Cancel</button>
                <button type="button" wire:click="savePosition" class="{{ Ui::BTN_PRIMARY }}" wire:loading.attr="disabled" wire:target="savePosition"><i class="fa-solid fa-floppy-disk text-xs"></i>Record the change</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</x-ui.page>
