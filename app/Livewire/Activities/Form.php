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

/** FRD PL-01..PL-04, PL-08: record a planned activity, or edit its details. */
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
        if ($activity?->exists) {
            abort_if($activity->status->isReadOnly(), 403, 'This activity is read only.');
            $this->activity = $activity;
            $this->fill($activity->only([
                'title', 'purpose', 'expected_outputs', 'notes', 'source_reference', 'days', 'count_externals_in_per_head',
            ]));
            foreach (['activity_category_id', 'activity_type_id', 'organising_department_id', 'budget_line_id'] as $key) {
                $this->{$key} = (string) ($activity->{$key} ?? '');
            }
            $this->source_received_on = $activity->source_received_on?->toDateString() ?? '';
            $this->start_date = $activity->start_date->toDateString();
            $this->expected_outputs ??= '';
            $this->notes ??= '';
        } else {
            $this->days = 1;
            $this->source_received_on = today()->toDateString();
        }
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
            'memo' => [$creating ? 'nullable' : 'prohibited', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:'.config('activities.max_document_kb')],
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return ['purpose' => 'objective', 'organising_department_id' => 'requesting department', 'activity_category_id' => 'category', 'memo' => 'source document'];
    }

    public function save(ActivityEditor $editor, DocumentStore $documents): void
    {
        $data = $this->validate();
        $user = Auth::user();

        $details = collect($data)->only([
            'title', 'purpose', 'expected_outputs', 'notes', 'activity_category_id', 'activity_type_id', 'organising_department_id',
            'budget_line_id', 'source_reference', 'source_received_on', 'start_date', 'days', 'count_externals_in_per_head',
        ])->map(fn ($v) => $v === '' ? null : $v)->all();

        $activity = $this->attempt(function () use ($editor, $documents, $details, $data, $user) {
            if ($this->activity) {
                return $editor->update($this->activity, $details, $user);
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
            session()->flash('notify', ['type' => 'success', 'message' => $this->activity ? 'Activity updated.' : 'Activity recorded as a draft. Add the team and costs next.']);
            $this->redirectRoute('activities.show', $activity, navigate: true);
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
            'budgetLines' => BudgetLine::query()->where('financial_year', $fy)->when($this->organising_department_id, fn ($q, $id) => $q->where('department_id', $id))->orderBy('name')->get(),
            'regions' => Region::query()->orderBy('name')->get(['id', 'name']),
            'counties' => County::query()->when($this->region_id, fn ($q, $id) => $q->whereHas('constituencies', fn ($c) => $c->where('region_id', $id)))->orderBy('name')->get(['id', 'name']),
            'constituencies' => $this->county_id ? Constituency::query()->where('county_id', $this->county_id)->orderBy('name')->get(['id', 'name']) : collect(),
        ])->title($this->activity ? 'Edit activity' : 'Record activity');
    }
}
