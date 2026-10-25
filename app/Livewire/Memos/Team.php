<?php

namespace App\Livewire\Memos;

use App\Enums\EmploymentType;
use App\Enums\ExternalCategory;
use App\Enums\ParticipantRole;
use App\Enums\TravelMode;
use App\Livewire\Concerns\RunsActions;
use App\Models\Activity;
use App\Models\CostCategory;
use App\Models\Department;
use App\Models\Designation;
use App\Models\DsaRate;
use App\Models\Office;
use App\Models\Staff;
use App\Rules\DsaGrade;
use App\Services\AccessLogger;
use App\Services\ActivityEditor;
use App\Services\ParticipantChecks;
use App\Services\StaffRecords;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * A Head of Department builds the team and planned costs of their own memo
 * while it is with them (a draft, or returned for correction), and adds an
 * officer to the staff list if one is missing. DSA is computed from each
 * officer's job grade and the location, as for the Office of the CEO.
 *
 * Deliberately narrower than the Office of the CEO's activity page: an
 * officer held by another approved activity is shown as unavailable, with
 * the days but without naming the activity, and cannot be added (only a
 * release, or the Office of the CEO, can clear it); no field-day totals
 * across activities are shown.
 */
#[Layout('layouts.admin')]
class Team extends Component
{
    use RunsActions;

    public Activity $activity;

    public bool $showAdd = false;

    public bool $showExternal = false;

    public bool $showRemove = false;

    public bool $showDays = false;

    public bool $showCost = false;

    public bool $showStaffForm = false;

    // Add officers.
    public string $staffSearch = '';

    public string $staffDepartment = '';

    /** @var list<int|string> */
    public array $selectedStaff = [];

    public string $role = 'member';

    // A group added by number, without names (e.g. 10 FAMs).
    public bool $showGroup = false;

    public string $groupLabel = '';

    public string $groupCount = '';

    public string $groupGrade = '';

    // External participant.
    public string $externalName = '';

    public string $externalOrganisation = '';

    public string $externalCategory = 'other';

    // One participant's days.
    public ?int $participantId = null;

    public string $daysStart = '';

    public ?int $daysCount = null;

    // A planned cost line.
    public ?int $costId = null;

    public string $costCategoryId = '';

    public string $costParticipantId = '';

    public string $costDescription = '';

    public string $costAmount = '';

    public string $costTravelMode = '';

    // An officer missing from the staff list.
    public string $staffNumber = '';

    public string $staffName = '';

    public string $staffDepartmentId = '';

    public string $staffDesignationId = '';

    public string $staffJobGrade = '';

    public string $staffOfficeId = '';

    public function mount(Activity $activity, AccessLogger $log): void
    {
        abort_unless($activity->isVisibleTo(Auth::user()), 403);
        $this->activity = $activity;
        $log->log(AccessLogger::VIEW, $activity, ['page' => 'team']);
    }

    private function done(string $message, array $warnings = []): void
    {
        $this->reset([
            'showAdd', 'showExternal', 'showGroup', 'groupLabel', 'groupCount', 'groupGrade', 'showRemove', 'showDays', 'showCost', 'showStaffForm',
            'staffSearch', 'staffDepartment', 'selectedStaff', 'role', 'externalName', 'externalOrganisation', 'externalCategory',
            'participantId', 'daysStart', 'daysCount', 'costId', 'costCategoryId', 'costParticipantId', 'costDescription', 'costAmount', 'costTravelMode',
        ]);
        $this->activity->refresh();
        $this->toast($message);
        $this->warn($warnings);
    }

    private function roleRule(): string
    {
        return 'in:'.implode(',', array_column(ParticipantRole::cases(), 'value'));
    }

    // ── Team ───────────────────────────────────────────────────────────

    public function addStaff(ActivityEditor $editor): void
    {
        $this->validate([
            'selectedStaff' => ['required', 'array', 'min:1'],
            'selectedStaff.*' => ['integer', 'exists:staff,id'],
            'role' => ['required', $this->roleRule()],
        ], ['selectedStaff.required' => 'Tick at least one officer.'], ['selectedStaff' => 'officers']);

        $result = $this->attempt(fn () => $editor->addStaff($this->activity, array_map('intval', $this->selectedStaff), ParticipantRole::from($this->role), Auth::user()));

        if (is_array($result)) {
            $this->done($result['added'].' added to the team.', $result['warnings']);
        }
    }

    /**
     * Officers shown in Add officers: active, not on the team, matching the search and department.
     *
     * @return Collection<int, Staff>
     */
    private function candidates(): Collection
    {
        $onTeam = $this->activity->participants()->whereNotNull('staff_id')->pluck('staff_id')->all();

        return Staff::query()->active()->search($this->staffSearch)->whereNotIn('id', $onTeam)
            ->when($this->staffDepartment, fn ($q, $id) => $q->where('department_id', $id))
            ->with(['department', 'designation'])->orderBy('name')->limit(100)->get();
    }

    /**
     * Ticks every officer shown who can be added, or clears them if they are all ticked already.
     * Officers on another approved activity on these days are left out: a Head of Department cannot add them.
     */
    public function toggleShown(ParticipantChecks $checks): void
    {
        $unavailable = $checks->unavailableStaff($this->activity->start_date, $this->activity->end_date, $this->activity->id);
        $shown = $this->candidates()->pluck('id')->reject(fn (int $id) => isset($unavailable[$id]));
        $selected = collect($this->selectedStaff)->map(fn ($id) => (int) $id);

        $this->selectedStaff = ($shown->diff($selected)->isEmpty() ? $selected->diff($shown) : $selected->merge($shown))->unique()->values()->all();
    }

    public function addExternal(ActivityEditor $editor): void
    {
        $this->validate([
            'externalName' => ['required', 'string', 'max:255'],
            'externalOrganisation' => ['nullable', 'string', 'max:255'],
            'externalCategory' => ['required', 'in:'.implode(',', array_column(ExternalCategory::cases(), 'value'))],
            'role' => ['required', $this->roleRule()],
        ], [], ['externalName' => 'name', 'externalOrganisation' => 'organisation']);

        if ($this->attempt(fn () => $editor->addExternal($this->activity, [
            'external_name' => $this->externalName,
            'external_organisation' => $this->externalOrganisation ?: null,
            'external_category' => ExternalCategory::from($this->externalCategory),
        ], ParticipantRole::from($this->role), Auth::user()))) {
            $this->done('External participant added.');
        }
    }

    /** Officers added by number without their names, e.g. 10 Fund Account Managers. */
    public function addGroup(ActivityEditor $editor): void
    {
        $this->validate([
            'groupLabel' => ['required', 'string', 'max:255'],
            'groupCount' => ['required', 'integer', 'min:1', 'max:500'],
            'groupGrade' => ['required', new DsaGrade(EmploymentType::Attachee)],
            'role' => ['required', $this->roleRule()],
        ], [], ['groupLabel' => 'who they are', 'groupCount' => 'number', 'groupGrade' => 'DSA scale']);

        if ($this->attempt(fn () => $editor->addGroup($this->activity, [
            'group_label' => $this->groupLabel,
            'headcount' => $this->groupCount,
            'job_grade' => $this->groupGrade,
        ], ParticipantRole::from($this->role), Auth::user()))) {
            $this->done("{$this->groupCount} × {$this->groupLabel} added. DSA is worked out for each of them.");
        }
    }

    public function confirmRemove(int $id): void
    {
        $this->activity->participants()->findOrFail($id);
        $this->participantId = $id;
        $this->showRemove = true;
    }

    public function removeParticipant(ActivityEditor $editor): void
    {
        $participant = $this->activity->participants()->findOrFail($this->participantId);

        if ($this->attempt(fn () => $editor->removeParticipant($participant, Auth::user())) !== null) {
            $this->done($participant->displayName().' removed from the team.');
        }
    }

    public function openDays(int $id): void
    {
        $participant = $this->activity->participants()->findOrFail($id);
        $this->resetErrorBag();
        $this->participantId = $id;
        $this->daysStart = $participant->startOn($this->activity)->toDateString();
        $this->daysCount = $participant->plannedDays($this->activity);
        $this->showDays = true;
    }

    public function saveDays(ActivityEditor $editor): void
    {
        $this->validate([
            'daysStart' => ['required', 'date', 'after_or_equal:'.$this->activity->start_date->toDateString(), 'before_or_equal:'.$this->activity->end_date->toDateString()],
            'daysCount' => ['required', 'integer', 'min:1', 'max:'.$this->activity->days],
        ], [
            'daysStart.after_or_equal' => 'The first day must be within the activity.',
            'daysStart.before_or_equal' => 'The first day must be within the activity.',
        ], ['daysStart' => 'first day', 'daysCount' => 'number of days']);

        $start = Carbon::parse($this->daysStart);
        $end = $start->copy()->addDays($this->daysCount - 1);

        if ($end->gt($this->activity->end_date)) {
            $this->addError('daysCount', sprintf('From %s the activity has only %d days left.', $start->format('d M Y'), (int) $start->diffInDays($this->activity->end_date) + 1));

            return;
        }

        $participant = $this->activity->participants()->findOrFail($this->participantId);

        if ($this->attempt(fn () => $editor->setParticipantDates($participant, $start, $end, Auth::user())) !== null) {
            $this->done($participant->displayName()."'s days updated.");
        }
    }

    // ── Costs ──────────────────────────────────────────────────────────

    public function openCost(?int $id = null): void
    {
        $this->resetErrorBag();
        $this->reset(['costCategoryId', 'costParticipantId', 'costDescription', 'costAmount', 'costTravelMode']);
        $this->costId = $id;

        if ($id) {
            $line = $this->activity->costs()->where('is_computed', false)->findOrFail($id);
            $this->costCategoryId = (string) $line->cost_category_id;
            $this->costParticipantId = (string) ($line->activity_participant_id ?? '');
            $this->costDescription = (string) $line->description;
            $this->costAmount = (string) $line->estimated_amount;
            $this->costTravelMode = (string) ($line->travel_mode?->value ?? '');
        }

        $this->showCost = true;
    }

    public function saveCost(ActivityEditor $editor): void
    {
        $this->validate([
            // DSA is calculated from the team and location, never typed in.
            'costCategoryId' => ['required', Rule::exists('cost_categories', 'id')->where(fn ($q) => $q->where('code', '!=', 'dsa'))],
            'costParticipantId' => ['nullable', 'integer'],
            'costDescription' => ['nullable', 'string', 'max:255'],
            'costAmount' => ['required', 'regex:/^\d{1,3}(,?\d{3})*(\.\d{1,2})?$/'],
            'costTravelMode' => ['nullable', 'in:'.implode(',', array_column(TravelMode::cases(), 'value'))],
        ], ['costAmount.regex' => 'Enter an amount such as 12,500.00.'], ['costCategoryId' => 'cost type', 'costAmount' => 'amount']);

        if ($this->costParticipantId && ! $this->activity->participants()->whereKey($this->costParticipantId)->exists()) {
            $this->addError('costParticipantId', 'Choose someone on this team.');

            return;
        }

        $line = $this->costId ? $this->activity->costs()->where('is_computed', false)->findOrFail($this->costId) : null;

        if ($this->attempt(fn () => $editor->savePlannedCost($this->activity, [
            'cost_category_id' => (int) $this->costCategoryId,
            'activity_participant_id' => $this->costParticipantId ? (int) $this->costParticipantId : null,
            'description' => $this->costDescription ?: null,
            'estimated_amount' => $this->costAmount,
            'travel_mode' => $this->costTravelMode ?: null,
        ], Auth::user(), $line)) !== null) {
            $this->done('Planned cost saved.');
        }
    }

    public function removeCost(int $id, ActivityEditor $editor): void
    {
        $line = $this->activity->costs()->findOrFail($id);

        if ($this->attempt(fn () => $editor->removePlannedCost($line, Auth::user())) !== null) {
            $this->done('Cost line removed.');
        }
    }

    // ── An officer missing from the staff list ─────────────────────────

    public function openStaffForm(): void
    {
        $this->resetErrorBag();
        $this->reset(['staffNumber', 'staffName', 'staffDesignationId', 'staffJobGrade', 'staffOfficeId']);
        $this->staffDepartmentId = (string) (Auth::user()->department_id ?? '');
        $this->showStaffForm = true;
    }

    public function saveStaff(StaffRecords $records): void
    {
        $data = $this->validate([
            'staffNumber' => ['required', 'string', 'max:30', Rule::unique('staff', 'staff_number')],
            'staffName' => ['required', 'string', 'max:255'],
            'staffDepartmentId' => ['required', 'exists:departments,id'],
            'staffDesignationId' => ['nullable', 'exists:designations,id'],
            'staffJobGrade' => ['nullable', 'string', 'max:20', new DsaGrade],
            'staffOfficeId' => ['nullable', 'exists:offices,id'],
        ], ['staffNumber.unique' => 'An officer with this PF number is already on the staff list. Search for them instead.'],
            ['staffNumber' => 'PF number', 'staffName' => 'name', 'staffDepartmentId' => 'department', 'staffJobGrade' => 'job grade']);

        $staff = $this->attempt(fn () => $records->save([
            'staff_number' => $data['staffNumber'],
            'name' => $data['staffName'],
            'department_id' => $data['staffDepartmentId'],
            'designation_id' => $data['staffDesignationId'] ?: null,
            'job_grade' => $data['staffJobGrade'] ?: null,
            'office_id' => $data['staffOfficeId'] ?: null,
        ], Auth::user()));

        if ($staff instanceof Staff) {
            // Back to the picker with the new officer ticked.
            $this->showStaffForm = false;
            $this->showAdd = true;
            $this->staffSearch = $staff->staff_number;
            $this->selectedStaff = [...array_map('intval', $this->selectedStaff), $staff->id];
            $this->toast("{$staff->name} added to the staff list and ticked. Press Add to team.");
        }
    }

    public function render(ParticipantChecks $checks): View
    {
        $activity = $this->activity->load([
            'organisingDepartment', 'locations.county', 'locations.region', 'locations.constituency',
            'participants.staff', 'participants.department', 'participants.designation', 'costs.category', 'costs.participant.staff',
        ]);

        // Overlaps are flagged with the days, never named: the other activity may belong to another department.
        $overlapping = array_keys($checks->conflictsFor($activity));

        $candidates = collect();
        $unavailable = [];
        $pending = [];
        if ($this->showAdd) {
            $candidates = $this->candidates();
            $unavailable = $checks->unavailableStaff($activity->start_date, $activity->end_date, $activity->id);
            $pending = $checks->pendingStaff($activity->start_date, $activity->end_date, $activity->id);
        }

        return view('livewire.memos.team', [
            'a' => $activity,
            'editable' => $activity->status->isWithDepartment(),
            'overlapping' => $overlapping,
            'candidates' => $candidates,
            'unavailable' => $unavailable,
            'pending' => $pending,
            'selectedIds' => array_map('intval', $this->selectedStaff),
            'roles' => ParticipantRole::cases(),
            'externalCategories' => ExternalCategory::cases(),
            'grades' => DsaRate::grades(),
            'travelModes' => TravelMode::cases(),
            'costCategories' => CostCategory::query()->active()->where('code', '!=', 'dsa')->ordered()->get(['id', 'name']),
            'departments' => Department::query()->active()->ordered()->get(['id', 'name']),
            'designations' => Designation::query()->active()->ordered()->get(['id', 'name']),
            'offices' => Office::query()->active()->ordered()->get(['id', 'name']),
            'selected' => $this->participantId ? $activity->participants->firstWhere('id', $this->participantId) : null,
        ])->title('Team and costs · '.$activity->reference);
    }
}
