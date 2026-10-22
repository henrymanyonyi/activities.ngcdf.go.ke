<?php

namespace App\Livewire\Memos;

use App\Enums\ActivityStatus;
use App\Enums\ReviewOffice;
use App\Livewire\Concerns\RunsActions;
use App\Models\Activity;
use App\Models\ActivityDocument;
use App\Models\Constituency;
use App\Models\County;
use App\Models\Region;
use App\Services\AccessLogger;
use App\Services\ActivityEditor;
use App\Services\ActivityLifecycle;
use App\Services\BudgetPosition;
use App\Services\DocumentStore;
use App\Services\MemoDocument;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * A Head of Department's view of one of their department's memos: the
 * details, supporting documents, where it is and its history, with the
 * actions open to them (edit, add locations, attach, preview the generated
 * memo and annexes, submit or resubmit, discard a draft). The team and
 * costs are built on Memos\Team.
 */
#[Layout('layouts.admin')]
class Show extends Component
{
    use RunsActions;
    use WithFileUploads;

    public Activity $activity;

    public bool $showSubmit = false;

    public bool $showDiscard = false;

    public string $office = 'assistant_chief_of_staff';

    public string $note = '';

    public $document = null;

    public bool $showLocation = false;

    // Activity report, once the activity is complete
    public bool $showReport = false;

    public string $outputsAchieved = '';

    public string $findings = '';

    public string $recommendations = '';

    /** @var array<int, string> cost line id => amount actually spent */
    public array $actuals = [];

    public $reportFile = null;

    public string $locRegion = '';

    public string $locCounty = '';

    public string $locConstituency = '';

    public string $locVenue = '';

    public function mount(Activity $activity, AccessLogger $log): void
    {
        abort_unless($activity->isVisibleTo(Auth::user()), 403);
        $this->activity = $activity;
        $log->log(AccessLogger::VIEW, $activity); // CF-06: every view of an activity
    }

    public function openSubmit(): void
    {
        $this->office = ($this->activity->review_office ?? ReviewOffice::AssistantChiefOfStaff)->value;
        $this->note = '';
        $this->resetErrorBag();
        $this->showSubmit = true;
    }

    public function submit(ActivityLifecycle $lifecycle): void
    {
        $this->validate([
            'office' => ['required', 'in:'.implode(',', array_column(ReviewOffice::cases(), 'value'))],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [], ['note' => 'note to the reviewer']);

        $resubmitting = $this->activity->status === ActivityStatus::ReturnedToHod;

        if ($this->attempt(fn () => $lifecycle->submitForReview($this->activity, Auth::user(), ReviewOffice::from($this->office), $this->note))) {
            $this->showSubmit = false;
            $this->activity->refresh();
            $this->toast($resubmitting ? 'Resubmitted. The reviewer has been told.' : 'Submitted for review. The '.$this->activity->review_office?->label().' has been told.');
        }
    }

    public function discard(ActivityLifecycle $lifecycle): void
    {
        if ($this->attempt(fn () => $lifecycle->discardDraft($this->activity, Auth::user()))) {
            session()->flash('notify', ['type' => 'success', 'message' => 'Draft discarded.']);
            $this->redirectRoute('memos.index', navigate: true);
        }
    }

    public function uploadDocument(ActivityEditor $editor, DocumentStore $store): void
    {
        $this->validate(['document' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png', 'max:'.config('activities.max_document_kb')]], [], ['document' => 'document']);

        $kind = $this->attempt(function () use ($editor, $store) {
            $editor->authorizeDetails($this->activity, Auth::user());

            return DB::transaction(function () use ($store) {
                $kind = $this->activity->documents()->where('kind', ActivityDocument::KIND_MEMO)->exists() ? ActivityDocument::KIND_OTHER : ActivityDocument::KIND_MEMO;
                $store->store($this->activity, $this->document, $kind, Auth::user());

                return $kind;
            });
        });

        if ($kind) {
            $this->reset('document');
            $this->toast($kind === ActivityDocument::KIND_MEMO ? 'Signed memo attached.' : 'Supporting document attached.');
        }
    }

    public function removeLocation(int $id, ActivityEditor $editor): void
    {
        $location = $this->activity->locations()->findOrFail($id);

        if ($this->attempt(fn () => $editor->removeLocation($location, Auth::user()), 'Location removed. Add the right one with Edit memo.')) {
            $this->activity->refresh();
        }
    }

    public function updatedLocCounty(): void
    {
        $this->locConstituency = '';
    }

    public function updatedLocConstituency(string $value): void
    {
        if ($value && $c = Constituency::find($value)) {
            $this->locCounty = (string) $c->county_id;
            $this->locRegion = (string) $c->region_id;
        }
    }

    /** MD-04: another location; the county sets the DSA destination. */
    public function addLocation(ActivityEditor $editor): void
    {
        $this->validate([
            'locRegion' => ['nullable', 'exists:regions,id'],
            'locCounty' => ['nullable', 'exists:counties,id'],
            'locConstituency' => ['nullable', 'exists:constituencies,id'],
            'locVenue' => ['nullable', 'string', 'max:255'],
        ]);

        if ($this->attempt(fn () => $editor->addLocation($this->activity, [
            'region_id' => $this->locRegion ?: null,
            'county_id' => $this->locCounty ?: null,
            'constituency_id' => $this->locConstituency ?: null,
            'venue' => $this->locVenue ?: null,
        ], Auth::user()), 'Location added.')) {
            $this->reset(['showLocation', 'locRegion', 'locCounty', 'locConstituency', 'locVenue']);
            $this->activity->refresh();
        }
    }

    public function openReport(): void
    {
        $this->resetValidation();
        $report = $this->activity->report;
        $this->outputsAchieved = (string) $report?->outputs_achieved;
        $this->findings = (string) $report?->findings;
        $this->recommendations = (string) $report?->recommendations;
        $this->actuals = $this->activity->costs()->get()
            ->mapWithKeys(fn ($line) => [$line->id => $line->actual_amount !== null ? Money::format(Money::toCents($line->actual_amount)) : ''])->all();
        $this->reportFile = null;
        $this->showReport = true;
    }

    public function submitReport(ActivityLifecycle $lifecycle): void
    {
        $this->validate([
            'outputsAchieved' => ['required', 'string', 'max:5000'],
            'findings' => ['nullable', 'string', 'max:5000'],
            'recommendations' => ['nullable', 'string', 'max:5000'],
            'actuals.*' => ['required', 'regex:/^\d{1,3}(,?\d{3})*(\.\d{1,2})?$/'],
            'reportFile' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:'.config('activities.max_document_kb')],
        ], ['actuals.*.required' => 'Enter what was spent (0 if nothing).', 'actuals.*.regex' => 'Enter an amount such as 25,000.00.'], ['outputsAchieved' => 'what the activity achieved']);

        $done = $this->attempt(fn () => $lifecycle->submitReport($this->activity, [
            'outputs_achieved' => $this->outputsAchieved,
            'findings' => $this->findings,
            'recommendations' => $this->recommendations,
        ], $this->actuals, $this->reportFile, Auth::user()), 'Report submitted to the Office of the CEO. What you spent now shows against your budget.');

        if ($done) {
            $this->showReport = false;
            $this->activity->refresh();
        }
    }

    public function render(MemoDocument $memoDocument, BudgetPosition $budget): View
    {
        $activity = $this->activity->load([
            'category', 'type', 'organisingDepartment', 'budgetLine', 'locations.region', 'locations.county', 'locations.constituency',
            'documents', 'statusHistory.user', 'reviewNotes.user', 'creator', 'responsibleHod', 'approver', 'participants',
            'report.submitter', 'report.reviewer', 'report.document', 'costs.category', 'costs.participant.staff',
        ]);

        return view('livewire.memos.show', [
            'a' => $activity,
            'editable' => $activity->status->isWithDepartment(),
            'canDiscard' => $activity->isDepartmentDraft(),
            'issues' => $activity->status->isWithDepartment() ? $memoDocument->issues($activity) : [],
            'budget' => $activity->organising_department_id ? $budget->forActivity($activity) : null,
            'offices' => ReviewOffice::cases(),
            'regions' => $this->showLocation ? Region::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'counties' => $this->showLocation ? County::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'constituencies' => $this->showLocation && $this->locCounty ? Constituency::query()->where('county_id', $this->locCounty)->orderBy('name')->get(['id', 'name']) : collect(),
        ])->title($activity->reference.' · '.$activity->title);
    }
}
