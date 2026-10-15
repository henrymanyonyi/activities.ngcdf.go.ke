<?php

namespace App\Livewire\Activities;

use App\Livewire\Concerns\RunsActions;
use App\Models\Activity;
use App\Models\ActivityCategory;
use App\Models\ActivityDocument;
use App\Models\ActivityType;
use App\Models\BudgetLine;
use App\Models\Constituency;
use App\Models\County;
use App\Models\Department;
use App\Models\Region;
use App\Models\User;
use App\Services\ActivityEditor;
use App\Services\DocumentStore;
use App\Support\FinancialYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * FRD PL-01..PL-04, PL-08: record a planned activity, or edit its details.
 * Also the activity memo form: a Head of Department prepares their own
 * department's memo here (department fixed to theirs), and the Office of the
 * CEO can name the Head of Department a memo is prepared on behalf of.
 */
#[Layout('layouts.admin')]
class Form extends Component
{
    use RunsActions;
    use WithFileUploads;

    public ?Activity $activity = null;

    public string $title = '';

    public string $purpose = '';

    public string $expected_outputs = '';

    public string $notes = '';

    public string $justification = '';

    public string $target_audience = '';

    public string $requested_budget = '';

    public string $responsible_hod_id = '';

    public string $responsible_hod_name = '';

    /** updated_at when the form was opened, so a save cannot silently overwrite someone else's (concurrent edits). */
    public string $loadedVersion = '';

    public string $activity_category_id = '';

    public string $activity_type_id = '';

    public string $organising_department_id = '';

    public string $budget_line_id = '';

    public string $source_reference = '';

    public string $source_received_on = '';

    public string $start_date = '';

    /** Null by default, set in mount(): Livewire rehydrates a null value to the class default, so a default of 1 would silently undo a cleared field. */
    public ?int $days = null;

    public bool $count_externals_in_per_head = true;

    // First location and source document (create only).
    public string $region_id = '';

    public string $county_id = '';

    public string $constituency_id = '';

    public string $venue = '';

    public $memo = null;

    public function mount(?Activity $activity = null): void
    {
        $user = Auth::user();

        if ($activity?->exists) {
            abort_if($activity->status->isReadOnly(), 403, 'This activity is read only.');
            abort_unless($this->isOffice() || ($activity->isVisibleTo($user) && $activity->status->isWithDepartment()), 403, 'This memo is with the Office of the CEO and cannot be changed now.');
            $this->activity = $activity;
            $this->loadedVersion = (string) $activity->updated_at?->toIso8601String();
            // Optional columns are NULL in the database but strings here, so cast before filling.
            foreach (['title', 'purpose', 'expected_outputs', 'justification', 'target_audience', 'requested_budget', 'notes', 'source_reference', 'activity_category_id', 'activity_type_id', 'organising_department_id', 'budget_line_id', 'responsible_hod_id', 'responsible_hod_name'] as $key) {
                $this->{$key} = (string) ($activity->{$key} ?? '');
            }
            $this->days = $activity->days;
            $this->count_externals_in_per_head = (bool) $activity->count_externals_in_per_head;
            $this->source_received_on = $activity->source_received_on?->toDateString() ?? '';
            $this->start_date = $activity->start_date->toDateString();
        } else {
            abort_unless($this->isOffice() || $user->department_id, 403, 'Your account is not linked to a department. Ask the System Administrator.');
            $this->days = 1;
            $this->source_received_on = today()->toDateString();
            if (! $this->isOffice()) {
                $this->organising_department_id = (string) $user->department_id;
            }
        }
    }

    /** The Office of the CEO (any department); otherwise a Head of Department (their own). */
    public function isOffice(): bool
    {
        return Auth::user()->can('activities.manage');
    }

    public function updatedOrganisingDepartmentId(): void
    {
        $this->responsible_hod_id = '';
    }

    public function updatedResponsibleHodId(string $value): void
    {
        $this->responsible_hod_name = $value !== '' ? (string) User::query()->whereKey($value)->value('name') : '';
    }

    public function updatedActivityCategoryId(): void
    {
        $this->activity_type_id = '';
    }

    public function updatedRegionId(): void
    {
        $this->county_id = '';
        $this->constituency_id = '';
    }

    public function updatedCountyId(): void
    {
        $this->constituency_id = '';
    }

    public function updatedConstituencyId(string $value): void
    {
        if ($value && $c = Constituency::find($value)) {
            $this->county_id = (string) $c->county_id;
            $this->region_id = (string) $c->region_id;
        }
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        $creating = ! $this->activity;

        return [
            'title' => ['required', 'string', 'max:255'],
            'purpose' => ['required', 'string', 'max:5000'],
            'expected_outputs' => ['nullable', 'string', 'max:5000'],
            'justification' => ['nullable', 'string', 'max:5000'],
            'target_audience' => ['nullable', 'string', 'max:255'],
            'requested_budget' => ['nullable', 'numeric', 'min:0', 'max:9999999999999', 'regex:/^\d+(\.\d{1,2})?$/'],
            'responsible_hod_id' => ['nullable', 'exists:users,id'],
            'responsible_hod_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'activity_category_id' => ['required', 'exists:activity_categories,id'],
            'activity_type_id' => ['nullable', 'exists:activity_types,id'],
            'organising_department_id' => ['required', 'exists:departments,id'],
            'budget_line_id' => ['nullable', 'exists:budget_lines,id'],
            'source_reference' => ['nullable', 'string', 'max:100'],
            'source_received_on' => ['nullable', 'date', 'before_or_equal:today'],
            'start_date' => ['required', 'date'],
            'days' => ['required', 'integer', 'min:1', 'max:120'],
            'count_externals_in_per_head' => ['boolean'],
            'region_id' => ['nullable', 'exists:regions,id'],
            'county_id' => ['nullable', 'exists:counties,id'],
            'constituency_id' => ['nullable', 'exists:constituencies,id'],
            'venue' => ['nullable', 'string', 'max:255'],
            // On edit the property is present but null, so 'file' must not run; documents are attached on the activity page.
            'memo' => $creating ? ['nullable', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:'.config('activities.max_document_kb')] : ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return ['purpose' => 'purpose', 'organising_department_id' => 'requesting department', 'activity_category_id' => 'category', 'memo' => 'source document', 'requested_budget' => 'estimated budget', 'target_audience' => 'participants', 'responsible_hod_id' => 'Head of Department', 'responsible_hod_name' => 'Head of Department'];
    }

    public function save(ActivityEditor $editor, DocumentStore $documents): void
    {
        $data = $this->validate();
        $user = Auth::user();

        if ($data['responsible_hod_id'] && ! User::query()->role(User::ROLE_HOD)->whereKey($data['responsible_hod_id'])->where('department_id', $data['organising_department_id'])->exists()) {
            $this->addError('responsible_hod_id', 'Choose a Head of Department account of the requesting department.');

            return;
        }

        if ($this->activity && $this->activity->fresh()?->updated_at?->toIso8601String() !== $this->loadedVersion) {
            $this->toast('Someone else saved this memo after you opened it. Reload the page to see their changes, then make yours again.', 'error');

            return;
        }

        $fields = ['title', 'purpose', 'expected_outputs', 'justification', 'target_audience', 'requested_budget', 'notes', 'activity_category_id', 'activity_type_id', 'organising_department_id',
            'budget_line_id', 'source_reference', 'source_received_on', 'start_date', 'days', 'count_externals_in_per_head'];
        if ($this->isOffice()) {
            $fields = [...$fields, 'responsible_hod_id', 'responsible_hod_name'];
        }

        $details = collect($data)->only($fields)->map(fn ($v) => $v === '' ? null : $v)->all();

        $activity = $this->attempt(function () use ($editor, $documents, $details, $data, $user) {
            if ($this->activity) {
                return DB::transaction(function () use ($editor, $details, $data, $user) {
                    $activity = $editor->update($this->activity, $details, $user);

                    if ($activity->locations()->doesntExist() && ($data['county_id'] || $data['constituency_id'] || $data['region_id'] || $data['venue'])) {
                        $editor->addLocation($activity, collect($data)->only(['region_id', 'county_id', 'constituency_id', 'venue'])->map(fn ($v) => $v ?: null)->all(), $user);
                    }

                    return $activity;
                });
            }

            return DB::transaction(function () use ($editor, $documents, $details, $data, $user) {
                $activity = $editor->create($details, $user);

                if ($data['county_id'] || $data['constituency_id'] || $data['region_id'] || $data['venue']) {
                    $editor->addLocation($activity, collect($data)->only(['region_id', 'county_id', 'constituency_id', 'venue'])->map(fn ($v) => $v ?: null)->all(), $user);
                }

                if ($this->memo) {
                    $documents->store($activity, $this->memo, ActivityDocument::KIND_MEMO, $user);
                }

                return $activity;
            });
        });

        if ($activity instanceof Activity) {
            if ($this->isOffice()) {
                session()->flash('notify', ['type' => 'success', 'message' => $this->activity ? 'Activity updated.' : 'Activity recorded as a draft. Add the team and costs next.']);
                $this->redirectRoute('activities.show', $activity, navigate: true);
            } else {
                session()->flash('notify', ['type' => 'success', 'message' => $this->activity ? 'Memo saved.' : 'Memo saved as a draft. Attach the signed memo, then submit it for review.']);
                $this->redirectRoute('memos.show', $activity, navigate: true);
            }
        }
    }

    public function render(): View
    {
        $start = $this->start_date ? Carbon::parse($this->start_date) : null;
        $days = max(1, (int) $this->days);
        $fy = $start ? FinancialYear::for($start)->label() : FinancialYear::current()->label();

        return view('livewire.activities.form', [
            'endDate' => $start?->copy()->addDays($days - 1),
            'nights' => $days - 1,
            'categories' => ActivityCategory::query()->active()->ordered()->get(['id', 'name']),
            'types' => $this->activity_category_id ? ActivityType::query()->active()->where('activity_category_id', $this->activity_category_id)->orderBy('name')->get(['id', 'name']) : collect(),
            'departments' => Department::query()->active()->ordered()->get(['id', 'name']),
            'heads' => $this->isOffice() && $this->organising_department_id
                ? User::query()->role(User::ROLE_HOD)->where('department_id', $this->organising_department_id)->orderByDesc('is_active')->orderBy('name')->get(['id', 'name', 'is_active'])
                : collect(),
            'office' => $this->isOffice(),
            'budgetLines' => BudgetLine::query()->where('financial_year', $fy)->when($this->organising_department_id, fn ($q, $id) => $q->where('department_id', $id))->orderBy('name')->get(),
            'regions' => Region::query()->orderBy('name')->get(['id', 'name']),
            'counties' => County::query()->when($this->region_id, fn ($q, $id) => $q->whereHas('constituencies', fn ($c) => $c->where('region_id', $id)))->orderBy('name')->get(['id', 'name']),
            'constituencies' => $this->county_id ? Constituency::query()->where('county_id', $this->county_id)->orderBy('name')->get(['id', 'name']) : collect(),
        ])->title(match (true) {
            ! $this->isOffice() => $this->activity ? 'Edit memo' : 'Create activity memo',
            default => $this->activity ? 'Edit activity' : 'Record activity',
        });
    }
}
