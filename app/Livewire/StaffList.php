<?php

namespace App\Livewire;

use App\Enums\EmploymentType;
use App\Livewire\Concerns\RunsActions;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Office;
use App\Models\Staff;
use App\Rules\DsaGrade;
use App\Services\StaffImporter;
use App\Services\StaffRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * FRD MD-01: the staff list, maintained by the Office of the CEO on screen and
 * by Excel import. A Head of Department sees it and adds officers who are
 * missing, one by one or by importing a list, so they can build a team; they
 * correct only the records they added (App\Services\StaffRecords,
 * App\Services\StaffImporter).
 */
#[Layout('layouts.admin')]
#[Title('Staff list')]
class StaffList extends Component
{
    use RunsActions;
    use WithFileUploads;
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $department_id = '';

    #[Url(except: 'active')]
    public string $state = 'active';

    public bool $showForm = false;

    public bool $showImport = false;

    public ?int $editingId = null;

    public string $staff_number = '';

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $form_department_id = '';

    public string $designation_id = '';

    public string $job_grade = '';

    public string $office_id = '';

    public string $employment_type = 'permanent';

    public string $contract_ends_on = '';

    public bool $is_active = true;

    public $file = null;

    /** @var list<string> */
    public array $importErrors = [];

    /** What a checked file would do, before it is imported. */
    public ?string $importCheck = null;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'department_id', 'state'], true)) {
            $this->resetPage();
        }

        if ($property === 'file') {
            $this->reset(['importErrors', 'importCheck']);
        }
    }

    public function edit(?int $id = null): void
    {
        $this->resetErrorBag();
        $this->reset(['staff_number', 'name', 'email', 'phone', 'form_department_id', 'designation_id', 'job_grade', 'office_id', 'employment_type', 'contract_ends_on']);
        $this->is_active = true;
        $this->editingId = $id;

        if ($id) {
            $s = Staff::query()->findOrFail($id);
            abort_unless(app(StaffRecords::class)->mayEdit(Auth::user(), $s), 403);
            $this->staff_number = (string) $s->staff_number;
            $this->name = $s->name;
            $this->email = (string) $s->email;
            $this->phone = (string) $s->phone;
            $this->form_department_id = (string) ($s->department_id ?? '');
            $this->designation_id = (string) ($s->designation_id ?? '');
            $this->job_grade = (string) $s->job_grade;
            $this->office_id = (string) ($s->office_id ?? '');
            $this->employment_type = $s->employment_type->value;
            $this->contract_ends_on = (string) $s->contract_ends_on?->toDateString();
            $this->is_active = $s->is_active;
        }

        $this->showForm = true;
    }

    public function save(StaffRecords $records): void
    {
        $data = $this->validate([
            'staff_number' => [Rule::requiredIf($this->employment_type === EmploymentType::Permanent->value), 'nullable', 'string', 'max:30', Rule::unique('staff', 'staff_number')->ignore($this->editingId)],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'contract_ends_on' => ['nullable', 'date'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'form_department_id' => ['required', 'exists:departments,id'],
            'designation_id' => ['nullable', 'exists:designations,id'],
            'job_grade' => ['nullable', 'string', 'max:20', new DsaGrade(EmploymentType::tryFrom($this->employment_type) ?? EmploymentType::Permanent)],
            'office_id' => ['nullable', 'exists:offices,id'],
            'is_active' => ['boolean'],
        ], ['staff_number.required' => 'The PF number is required for permanent staff.'], ['staff_number' => 'PF number', 'form_department_id' => 'department', 'contract_ends_on' => 'contract end date']);

        $attributes = [
            'staff_number' => $data['staff_number'] ?: null,
            'name' => $data['name'],
            'employment_type' => $data['employment_type'],
            'contract_ends_on' => $data['contract_ends_on'] ?: null,
            'email' => $data['email'] ?: null,
            'phone' => $data['phone'] ?: null,
            'department_id' => $data['form_department_id'],
            'designation_id' => $data['designation_id'] ?: null,
            'job_grade' => $data['job_grade'] ?: null,
            'office_id' => $data['office_id'] ?: null,
            'is_active' => $data['is_active'],
        ];

        $staff = $this->editingId ? Staff::query()->findOrFail($this->editingId) : null;

        if ($this->attempt(fn () => $records->save($attributes, Auth::user(), $staff))) {
            $this->showForm = false;
            $this->toast($staff ? 'Staff record saved.' : "{$data['name']} added to the staff list.");
        }
    }

    /** Runs every check and reports what the import would do, without saving anything. */
    public function checkImport(StaffImporter $importer): void
    {
        abort_unless(Auth::user()->canAny(['reference.manage', 'staff.add']), 403);
        $this->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:'.config('activities.max_list_kb')]]);

        $result = $importer->import($this->file->getRealPath(), Auth::user(), dryRun: true);
        $this->importErrors = $result['errors'];
        $this->importCheck = $result['errors'] === []
            ? "The file is ready: {$result['created']} would be added and {$result['updated']} updated"
                .($result['skipped'] ? ", {$result['skipped']} left as they are" : '').'. Nothing has been saved yet.'
            : null;
    }

    public function import(StaffImporter $importer): void
    {
        abort_unless(Auth::user()->canAny(['reference.manage', 'staff.add']), 403);
        $this->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:'.config('activities.max_list_kb')]]);

        $result = $importer->import($this->file->getRealPath(), Auth::user());
        $this->importErrors = $result['errors'];
        $this->importCheck = null;

        if ($result['errors'] === []) {
            $this->reset(['showImport', 'file', 'importCheck']);
            $this->toast("Staff list imported: {$result['created']} added, {$result['updated']} updated."
                .($result['skipped'] ? " {$result['skipped']} already on the list were left as they are." : ''));
        }
    }

    public function render(): View
    {
        $staff = Staff::query()
            ->search($this->search)
            ->when($this->department_id, fn ($q, $id) => $q->where('department_id', $id))
            ->when($this->state === 'active', fn ($q) => $q->where('is_active', true))
            ->when($this->state === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($this->state === 'no_grade', fn ($q) => $q->where('is_active', true)->whereNull('job_grade'))
            ->with(['department', 'designation', 'office'])
            ->withCount('participations')
            ->orderBy('name')
            ->paginate(30);

        return view('livewire.staff-list', [
            'staff' => $staff,
            'departments' => Department::query()->ordered()->get(['id', 'name']),
            'designations' => Designation::query()->ordered()->get(['id', 'name']),
            'offices' => Office::query()->ordered()->get(['id', 'name']),
            'columns' => StaffImporter::COLUMNS,
            'employmentTypes' => EmploymentType::cases(),
            'records' => app(StaffRecords::class),
            'manages' => Auth::user()->can('reference.manage'),
            'counts' => [
                'active' => Staff::query()->where('is_active', true)->count(),
                'no_grade' => Staff::query()->where('is_active', true)->whereNull('job_grade')->count(),
            ],
        ]);
    }
}
