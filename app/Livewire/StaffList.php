<?php

namespace App\Livewire;

use App\Enums\EmploymentType;
use App\Livewire\Concerns\RunsActions;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Office;
use App\Models\ScheduledStaffChange;
use App\Models\Staff;
use App\Models\User;
use App\Rules\DsaGrade;
use App\Services\StaffChanges;
use App\Services\StaffImporter;
use App\Services\StaffRecords;
use App\Support\MobileNumber;
use Closure;
use Illuminate\Support\Collection;
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
 * App\Services\StaffImporter). The System Administrator, the CEO, the Chief
 * of Staff and the Assistant also record promotions, transfers and changes of
 * system access here (App\Services\StaffChanges), with their history.
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

    // Promotion, transfer or change of access
    public bool $showPosition = false;

    public ?int $positionStaffId = null;

    public string $posDepartment = '';

    public string $posDesignation = '';

    public string $posAccess = StaffChanges::KEEP;

    public bool $posReplaceHead = false;

    public string $posEffective = '';

    public string $posReason = '';

    public string $posReference = '';

    /** When the officer stops being Head: who becomes Head of that department on the same date. */
    public string $posSuccessor = '';

    /** Shown once after an account is made, never stored in plain text. */
    public ?string $oneTimePassword = null;

    public ?string $oneTimeFor = null;

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
            'phone' => ['nullable', 'string', 'max:30', fn (string $attribute, mixed $value, Closure $fail) => MobileNumber::isValid($value) || $fail('Enter a Kenyan mobile number, e.g. 0712 345 678.')],
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
            'phone' => MobileNumber::normalise($data['phone']),
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

    public function openPosition(int $id): void
    {
        abort_unless(Auth::user()->can('staff.reassign'), 403);
        $staff = Staff::query()->findOrFail($id);
        $this->resetErrorBag();
        $this->positionStaffId = $staff->id;
        $this->posDepartment = (string) $staff->department_id;
        $this->posDesignation = (string) $staff->designation_id;
        $this->posAccess = StaffChanges::KEEP;
        $this->posReplaceHead = false;
        $this->posEffective = today()->toDateString();
        $this->posReason = '';
        $this->posReference = '';
        $this->posSuccessor = '';
        $this->showPosition = true;
    }

    public function savePosition(StaffChanges $changes): void
    {
        abort_unless(Auth::user()->can('staff.reassign'), 403);
        $this->validate([
            'posDepartment' => ['required', 'exists:departments,id'],
            'posDesignation' => ['nullable', 'exists:designations,id'],
            'posAccess' => ['required', Rule::in(array_keys($changes->accessOptions(Auth::user())))],
            'posEffective' => ['required', 'date'],
            'posReason' => ['required', 'string', 'max:2000'],
            'posReference' => ['nullable', 'string', 'max:100'],
            'posSuccessor' => ['nullable', Rule::exists('staff', 'id')->where('is_active', true)->whereNull('deleted_at')],
        ], [], ['posDepartment' => 'department', 'posDesignation' => 'designation', 'posAccess' => 'system access', 'posEffective' => 'effective date', 'posReason' => 'reason', 'posReference' => 'reference', 'posSuccessor' => 'successor']);

        $staff = Staff::query()->findOrFail($this->positionStaffId);
        $data = [
            'department_id' => $this->posDepartment,
            'designation_id' => $this->posDesignation,
            'access' => $this->posAccess,
            'replace_head' => $this->posReplaceHead,
            'effective_on' => $this->posEffective,
            'reason' => $this->posReason,
            'reference' => $this->posReference,
        ];
        $successor = $this->posSuccessor !== '' ? Staff::query()->findOrFail((int) $this->posSuccessor) : null;
        $result = $this->attempt(fn () => $successor
            ? $changes->handOver($staff, $data, $successor, Auth::user())
            : ['head' => $changes->apply($staff, $data, Auth::user())]);

        if (! is_array($result)) {
            return;
        }

        $this->showPosition = false;
        foreach ($result as $outcome) {
            if ($outcome['password']) {
                [$this->oneTimePassword, $this->oneTimeFor] = [$outcome['password'], $outcome['account']->email];
            }
        }
        $head = $result['head'];
        $this->toast(match (true) {
            $head['scheduled'] !== null => "{$staff->name}: change scheduled from {$head['scheduled']->effective_on->format('d M Y')}".($successor ? ", with {$successor->name} as the new Head" : '').'. It applies on that date; you can cancel it until then.',
            $successor !== null => "{$staff->name}: change recorded. {$successor->name} is now the Head; their access applies at their next sign-in.",
            default => "{$staff->name}: change recorded".($head['account'] && $head['change']->to_role !== $head['change']->from_role ? '; their access applies at their next sign-in.' : '.'),
        });
    }

    public function cancelScheduled(int $id, StaffChanges $changes): void
    {
        $scheduled = ScheduledStaffChange::query()->with('staff')->findOrFail($id);
        $this->attempt(fn () => $changes->cancel($scheduled, Auth::user()), "The change for {$scheduled->staff->name} from {$scheduled->effective_on->format('d M Y')} is cancelled.");
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

    /** @return array<string, mixed> */
    private function positionData(): array
    {
        if (! $this->showPosition || ! $this->positionStaffId) {
            return ['positionStaff' => null, 'positionAccount' => null, 'accessOptions' => [], 'currentHead' => null, 'headDepartment' => null, 'scheduledChanges' => collect(), 'headedDepartment' => null, 'successorOptions' => collect()];
        }

        $changes = app(StaffChanges::class);
        $staff = Staff::query()->with(['department', 'designation', 'positionChanges.fromDepartment', 'positionChanges.toDepartment', 'positionChanges.fromDesignation', 'positionChanges.toDesignation', 'positionChanges.changer'])->find($this->positionStaffId);
        $account = $staff ? $changes->accountOf($staff) : null;
        $headWanted = $this->posAccess === User::ROLE_HOD && $this->posDepartment !== '';

        return [
            'positionStaff' => $staff,
            'positionAccount' => $account?->loadMissing('department'),
            'accessOptions' => $changes->accessOptions(Auth::user()),
            'currentHead' => $headWanted ? User::query()->role(User::ROLE_HOD)->where('is_active', true)->where('department_id', $this->posDepartment)->when($account, fn ($q) => $q->whereKeyNot($account->id))->first() : null,
            'headDepartment' => $headWanted ? Department::query()->find($this->posDepartment) : null,
            'scheduledChanges' => $staff ? ScheduledStaffChange::query()->where('staff_id', $staff->id)->with('scheduler')->latest('id')->limit(10)->get() : collect(),
            ...$this->successorData($account),
        ];
    }

    /**
     * When the officer is a Head and this change ends that, the department and the officers who may succeed them.
     *
     * @return array{headedDepartment: ?Department, successorOptions: Collection<int, Staff>}
     */
    private function successorData(?User $account): array
    {
        $headed = $account?->is_active && $account->roleName() === User::ROLE_HOD ? $account->department : null;
        $staysHead = in_array($this->posAccess, [StaffChanges::KEEP, User::ROLE_HOD], true) && (int) $this->posDepartment === (int) $headed?->id;
        if (! $headed || $staysHead) {
            return ['headedDepartment' => null, 'successorOptions' => collect()];
        }

        return [
            'headedDepartment' => $headed,
            'successorOptions' => Staff::query()->active()->where('department_id', $headed->id)->whereKeyNot($this->positionStaffId)->with('designation')->orderBy('name')->get(['id', 'name', 'designation_id']),
        ];
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
            'scheduledIds' => ScheduledStaffChange::query()->pending()->pluck('staff_id')->map(fn ($id) => (int) $id)->all(),
            'managerIds' => Department::query()->whereNotNull('manager_staff_id')->pluck('manager_staff_id')->map(fn ($id) => (int) $id)->all(),
            'columns' => StaffImporter::COLUMNS,
            'employmentTypes' => EmploymentType::cases(),
            'records' => app(StaffRecords::class),
            'manages' => Auth::user()->can('reference.manage'),
            'canAdd' => Auth::user()->canAny(['reference.manage', 'staff.add']),
            'canReassign' => Auth::user()->can('staff.reassign'),
            ...$this->positionData(),
            'counts' => [
                'active' => Staff::query()->where('is_active', true)->count(),
                'no_grade' => Staff::query()->where('is_active', true)->whereNull('job_grade')->count(),
            ],
        ]);
    }
}
