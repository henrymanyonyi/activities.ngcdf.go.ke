<?php

namespace App\Livewire;

use App\Livewire\Concerns\RunsActions;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Office;
use App\Models\Staff;
use App\Services\StaffImporter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/** FRD MD-01: the staff list, maintained by the Chief of Staff on screen and by Excel import. */
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

    public bool $is_active = true;

    public $file = null;

    /** @var list<string> */
    public array $importErrors = [];

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'department_id', 'state'], true)) {
            $this->resetPage();
        }
    }

    public function edit(?int $id = null): void
    {
        $this->resetErrorBag();
        $this->reset(['staff_number', 'name', 'email', 'phone', 'form_department_id', 'designation_id', 'job_grade', 'office_id']);
        $this->is_active = true;
        $this->editingId = $id;

        if ($id) {
            $s = Staff::query()->findOrFail($id);
            $this->staff_number = (string) $s->staff_number;
            $this->name = $s->name;
            $this->email = (string) $s->email;
            $this->phone = (string) $s->phone;
            $this->form_department_id = (string) ($s->department_id ?? '');
            $this->designation_id = (string) ($s->designation_id ?? '');
            $this->job_grade = (string) $s->job_grade;
            $this->office_id = (string) ($s->office_id ?? '');
            $this->is_active = $s->is_active;
        }

        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless(Auth::user()->can('reference.manage'), 403);

        $data = $this->validate([
            'staff_number' => ['required', 'string', 'max:30', Rule::unique('staff', 'staff_number')->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'form_department_id' => ['required', 'exists:departments,id'],
            'designation_id' => ['nullable', 'exists:designations,id'],
            'job_grade' => ['nullable', 'string', 'max:20'],
            'office_id' => ['nullable', 'exists:offices,id'],
            'is_active' => ['boolean'],
        ], [], ['staff_number' => 'PF number', 'form_department_id' => 'department']);

        $attributes = [
            'staff_number' => strtoupper(trim($data['staff_number'])),
            'name' => $data['name'],
            'email' => $data['email'] ?: null,
            'phone' => $data['phone'] ?: null,
            'department_id' => $data['form_department_id'],
            'designation_id' => $data['designation_id'] ?: null,
            'job_grade' => $data['job_grade'] ? strtoupper(trim($data['job_grade'])) : null,
            'office_id' => $data['office_id'] ?: null,
            'is_active' => $data['is_active'],
            'exited_on' => $data['is_active'] ? null : today(),
        ];

        $this->editingId ? Staff::query()->findOrFail($this->editingId)->update($attributes) : Staff::create($attributes + ['source' => 'manual', 'joined_on' => today()]);

        $this->showForm = false;
        $this->toast('Staff record saved.');
    }

    public function import(StaffImporter $importer): void
    {
        abort_unless(Auth::user()->can('reference.manage'), 403);
        $this->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:'.config('activities.max_list_kb')]]);

        $result = $importer->import($this->file->getRealPath());
        $this->importErrors = $result['errors'];

        if ($result['errors'] === []) {
            $this->reset(['showImport', 'file']);
            $this->toast("Staff list imported: {$result['created']} added, {$result['updated']} updated.");
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
            'counts' => [
                'active' => Staff::query()->where('is_active', true)->count(),
                'no_grade' => Staff::query()->where('is_active', true)->whereNull('job_grade')->count(),
            ],
        ]);
    }
}
