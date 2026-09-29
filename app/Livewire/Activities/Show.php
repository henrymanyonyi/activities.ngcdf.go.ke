<?php

namespace App\Livewire\Activities;

use App\Enums\ActivityStatus;
use App\Enums\DecisionType;
use App\Enums\ExternalCategory;
use App\Enums\ImprestStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipationStatus;
use App\Enums\TravelMode;
use App\Livewire\Concerns\RunsActions;
use App\Models\Activity;
use App\Models\ActivityDocument;
use App\Models\ActivityLocation;
use App\Models\ActivityParticipant;
use App\Models\Constituency;
use App\Models\CostCategory;
use App\Models\County;
use App\Models\Department;
use App\Models\Directive;
use App\Models\Region;
use App\Models\Staff;
use App\Services\AccessLogger;
use App\Services\ActivityCosting;
use App\Services\ActivityEditor;
use App\Services\ActivityLifecycle;
use App\Services\DocumentStore;
use App\Services\DsaCalculator;
use App\Services\ParticipantChecks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * One activity: details, team, costs, decisions, report and history, with
 * every lifecycle action the signed-in user's role allows (FRD 4.1).
 */
#[Layout('layouts.admin')]
class Show extends Component
{
    use RunsActions;
    use WithFileUploads;

    public Activity $activity;

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    // Which popup is open.
    public bool $showDecide = false;

    public bool $showRecordDecision = false;

    public bool $showPostpone = false;

    public bool $showExtend = false;

    public bool $showCancel = false;

    public bool $showComplete = false;

    public bool $showReport = false;

    public bool $showClose = false;

    public bool $showDiscard = false;

    public bool $showAddStaff = false;

    public bool $showAddExternal = false;

    public bool $showRemoveParticipant = false;

    public bool $showCost = false;

    public bool $showOverride = false;

    public bool $showLocation = false;

    public bool $showDirective = false;

    public bool $showImprest = false;

    public bool $showDocument = false;

    // Shared form state.
    public string $decision = 'approved';

    public string $comment = '';

    public string $memoReference = '';

    public string $memoDate = '';

    public $scan = null;

    public string $reason = '';

    public string $newStart = '';

    public int|string $newDays = '';

    public int|string $extraDays = 1;

    public string $actualDate = '';

    public string $reportReceivedOn = '';

    public string $outputsAchieved = '';

    public string $findings = '';

    public string $recommendations = '';

    public $reportFile = null;

    public string $staffSearch = '';

    /** @var list<int> */
    public array $selectedStaff = [];

    public string $role = 'member';

    /** @var array<int, string> */
    public array $conflictReasons = [];

    public string $externalName = '';

    public string $externalOrganisation = '';

    public string $externalCategory = 'other';

    public ?int $participantId = null;

    public ?int $costId = null;

    public string $costCategoryId = '';

    public string $costParticipantId = '';

    public string $costDescription = '';

    public string $costAmount = '';

    public string $costTravelMode = '';

    public string $overrideAmount = '';

    /** @var array<int, string> cost id => actual */
    public array $actuals = [];

    /** @var array<int, string> cost id => finance reference */
    public array $financeRefs = [];

    public string $locRegion = '';

    public string $locCounty = '';

    public string $locConstituency = '';

    public string $locVenue = '';

    public string $directiveBody = '';

    public string $directiveDepartment = '';

    public string $directiveDue = '';

    public string $imprestReference = '';

    public string $imprestStatus = '';

    public $document = null;

    public function mount(Activity $activity, AccessLogger $log): void
    {
        $this->activity = $activity;
        $log->log(AccessLogger::VIEW, $activity); // CF-06: every view of an activity
        $this->loadActuals();
    }

    private function loadActuals(): void
    {
        $this->actuals = $this->activity->costs()->pluck('actual_amount', 'id')->map(fn ($v) => $v === null ? '' : (string) $v)->all();
        $this->financeRefs = $this->activity->costs()->pluck('finance_reference', 'id')->map(fn ($v) => (string) $v)->all();
    }

    private function user()
    {
        return Auth::user();
    }

    private function done(?string $message = null, array $warnings = []): void
    {
        $this->reset([
            'showDecide', 'showRecordDecision', 'showPostpone', 'showExtend', 'showCancel', 'showComplete', 'showReport', 'showClose', 'showDiscard',
            'showAddStaff', 'showAddExternal', 'showRemoveParticipant', 'showCost', 'showOverride', 'showLocation', 'showDirective', 'showImprest', 'showDocument',
            'comment', 'memoReference', 'memoDate', 'scan', 'reason', 'newStart', 'newDays', 'extraDays', 'actualDate', 'reportReceivedOn', 'outputsAchieved',
            'findings', 'recommendations', 'reportFile', 'staffSearch', 'selectedStaff', 'conflictReasons', 'externalName', 'externalOrganisation',
            'participantId', 'costId', 'costCategoryId', 'costParticipantId', 'costDescription', 'costAmount', 'costTravelMode', 'overrideAmount',
            'locRegion', 'locCounty', 'locConstituency', 'locVenue', 'directiveBody', 'directiveDepartment', 'directiveDue', 'document',
        ]);
        $this->activity->refresh();
        $this->loadActuals();
        if ($message) {
            $this->toast($message);
        }
        $this->warn($warnings);
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['overview', 'team', 'costs', 'decisions', 'report', 'history'], true) ? $tab : 'overview';
    }

    // ── Decisions (7.5) ───────────────────────────────────────────────

    public function openDecide(string $decision): void
    {
        $this->decision = DecisionType::from($decision)->value;
        $this->showDecide = true;
    }

    public function decide(ActivityLifecycle $lifecycle): void
    {
        $this->validate(['comment' => [DecisionType::from($this->decision)->requiresComment() ? 'required' : 'nullable', 'string', 'max:2000']]);

        if ($this->attempt(fn () => $lifecycle->decide($this->activity, DecisionType::from($this->decision), $this->comment, $this->user()))) {
            $this->done('Decision recorded: '.DecisionType::from($this->decision)->label().'.');
        }
    }

    public function recordDecision(ActivityLifecycle $lifecycle): void
    {
        $this->validate([
            'decision' => ['required', 'in:approved,declined,returned'],
            'memoReference' => ['required', 'string', 'max:100'],
            'memoDate' => ['required', 'date', 'before_or_equal:today'],
            'scan' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.config('activities.max_document_kb')],
            'comment' => [DecisionType::from($this->decision)->requiresComment() ? 'required' : 'nullable', 'string', 'max:2000'],
        ], [], ['memoReference' => 'memo reference', 'memoDate' => 'memo date', 'scan' => 'scanned copy']);

        if ($this->attempt(fn () => $lifecycle->recordDecision($this->activity, DecisionType::from($this->decision), $this->comment, $this->memoReference, Carbon::parse($this->memoDate), $this->scan, $this->user()))) {
            $this->done('Decision recorded on the CEO\'s behalf.');
        }
    }

    public function submitForDecision(ActivityLifecycle $lifecycle): void
    {
        if ($this->attempt(fn () => $lifecycle->submitForDecision($this->activity, $this->user()))) {
            $this->done('Submitted to the CEO\'s decision queue.');
        }
    }

    // ── Execution (7.6 / 7.7 / 7.8) ───────────────────────────────────

    public function confirmCommencement(ActivityLifecycle $lifecycle): void
    {
        if ($this->attempt(fn () => $lifecycle->confirmCommencement($this->activity, $this->user()))) {
            $this->done('Commencement confirmed.');
        }
    }

    public function postpone(ActivityLifecycle $lifecycle): void
    {
        $this->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'newStart' => ['nullable', 'date'],
            'newDays' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);

        $warnings = $this->attempt(fn () => $lifecycle->postpone($this->activity, $this->reason, $this->user(), $this->newStart ? Carbon::parse($this->newStart) : null, $this->newDays ? (int) $this->newDays : null));
        if ($warnings !== null) {
            $this->done($this->activity->fresh()->status === ActivityStatus::AwaitingDecision ? 'Postponed beyond tolerance: returned to the CEO for decision.' : 'Postponement recorded.', is_array($warnings) ? $warnings : []);
        }
    }

    public function extend(ActivityLifecycle $lifecycle): void
    {
        $this->validate(['extraDays' => ['required', 'integer', 'min:1', 'max:60'], 'reason' => ['required', 'string', 'max:1000']]);

        $warnings = $this->attempt(fn () => $lifecycle->extend($this->activity, (int) $this->extraDays, $this->reason, $this->user()));
        if ($warnings !== null) {
            $this->done('Extension recorded and costs recalculated.', is_array($warnings) ? $warnings : []);
        }
    }

    public function cancel(ActivityLifecycle $lifecycle): void
    {
        $this->validate(['reason' => ['required', 'string', 'max:1000']]);

        if ($this->attempt(fn () => $lifecycle->cancel($this->activity, $this->reason, $this->user()))) {
            $this->done('Activity cancelled.');
        }
    }

    public function complete(ActivityLifecycle $lifecycle): void
    {
        $this->validate(['actualDate' => ['nullable', 'date', 'before_or_equal:today']]);

        if ($this->attempt(fn () => $lifecycle->complete($this->activity, $this->user(), $this->actualDate ? Carbon::parse($this->actualDate) : null))) {
            $this->done('Marked completed. The back-to-office report is due '.$this->activity->fresh()->report_due_on?->format('d M Y').'.');
        }
    }

    public function recordReport(ActivityLifecycle $lifecycle): void
    {
        $data = $this->validate([
            'reportReceivedOn' => ['required', 'date', 'before_or_equal:today'],
            'outputsAchieved' => ['required', 'string', 'max:5000'],
            'findings' => ['nullable', 'string', 'max:5000'],
            'recommendations' => ['nullable', 'string', 'max:5000'],
            'reportFile' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:'.config('activities.max_document_kb')],
        ], [], ['reportReceivedOn' => 'date received', 'outputsAchieved' => 'outputs achieved']);

        if ($this->attempt(fn () => $lifecycle->recordReport($this->activity, [
            'received_on' => $data['reportReceivedOn'],
            'outputs_achieved' => $data['outputsAchieved'],
            'findings' => $data['findings'] ?: null,
            'recommendations' => $data['recommendations'] ?: null,
        ], $this->reportFile, $this->user()))) {
            $this->done('Back-to-office report recorded.');
        }
    }

    public function close(ActivityLifecycle $lifecycle): void
    {
        if ($this->attempt(fn () => $lifecycle->close($this->activity, $this->user()))) {
            $this->done('Activity closed. It is now read only.');
        }
    }

    public function discard(ActivityLifecycle $lifecycle): void
    {
        if ($this->attempt(fn () => $lifecycle->discardDraft($this->activity, $this->user()))) {
            session()->flash('notify', ['type' => 'success', 'message' => 'Draft discarded.']);
            $this->redirectRoute('activities.index', navigate: true);
        }
    }

    // ── Team (7.3) ─────────────────────────────────────────────────────

    public function addStaff(ActivityEditor $editor): void
    {
        $this->validate([
            'selectedStaff' => ['required', 'array', 'min:1'],
            'selectedStaff.*' => ['integer', 'exists:staff,id'],
            'role' => ['required', 'in:'.implode(',', array_column(ParticipantRole::cases(), 'value'))],
            'reason' => [$this->activity->status->requiresAmendment() ? 'required' : 'nullable', 'string', 'max:1000'],
        ], [], ['selectedStaff' => 'officers', 'reason' => 'reason for the change']);

        $result = $this->attempt(fn () => $editor->addStaff($this->activity, array_map('intval', $this->selectedStaff), ParticipantRole::from($this->role), $this->user(), array_filter($this->conflictReasons), $this->reason ?: null));
        if (is_array($result)) {
            $this->done("{$result['added']} added.".($result['returned'] ? ' The change is beyond tolerance, so the activity is back with the CEO.' : ''), $result['warnings']);
        }
    }

    public function addExternal(ActivityEditor $editor): void
    {
        $this->validate([
            'externalName' => ['required', 'string', 'max:255'],
            'externalOrganisation' => ['nullable', 'string', 'max:255'],
            'externalCategory' => ['required', 'in:'.implode(',', array_column(ExternalCategory::cases(), 'value'))],
            'role' => ['required', 'in:'.implode(',', array_column(ParticipantRole::cases(), 'value'))],
            'reason' => [$this->activity->status->requiresAmendment() ? 'required' : 'nullable', 'string', 'max:1000'],
        ], [], ['externalName' => 'name']);

        if ($this->attempt(fn () => $editor->addExternal($this->activity, [
            'external_name' => $this->externalName,
            'external_organisation' => $this->externalOrganisation ?: null,
            'external_category' => ExternalCategory::from($this->externalCategory),
        ], ParticipantRole::from($this->role), $this->user(), $this->reason ?: null))) {
            $this->done('External participant added.');
        }
    }

    public function confirmRemoveParticipant(int $id): void
    {
        $this->participantId = $id;
        $this->showRemoveParticipant = true;
    }

    public function removeParticipant(ActivityEditor $editor): void
    {
        $participant = $this->activity->participants()->findOrFail($this->participantId);
        $this->validate(['reason' => [$this->activity->status->requiresAmendment() ? 'required' : 'nullable', 'string', 'max:1000']]);

        $returned = $this->attempt(fn () => $editor->removeParticipant($participant, $this->user(), $this->reason ?: null));
        if ($returned !== null) {
            $this->done('Removed from the team.'.($returned === true ? ' The change is beyond tolerance, so the activity is back with the CEO.' : ''));
        }
    }

    public function setAttendance(int $id, string $status, ActivityEditor $editor): void
    {
        $participant = $this->activity->participants()->findOrFail($id);

        if ($this->attempt(fn () => $editor->recordAttendance($participant, ParticipationStatus::from($status), null, $this->user()))) {
            $this->done();
        }
    }

    public function setDaysAttended(int $id, $days, ActivityEditor $editor): void
    {
        $participant = $this->activity->participants()->findOrFail($id);
        $days = max(0, min(366, (int) $days));

        if ($this->attempt(fn () => $editor->recordAttendance($participant, ParticipationStatus::Attended, $days, $this->user()))) {
            $this->done();
        }
    }

    // ── Costs (7.4 / 7.8) ──────────────────────────────────────────────

    public function openCost(?int $id = null): void
    {
        $this->resetErrorBag();
        $this->costId = $id;
        if ($id && $line = $this->activity->costs()->find($id)) {
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
            'costCategoryId' => ['required', 'exists:cost_categories,id'],
            'costParticipantId' => ['nullable', 'integer'],
            'costDescription' => ['nullable', 'string', 'max:255'],
            'costAmount' => ['required', 'regex:/^\d{1,3}(,?\d{3})*(\.\d{1,2})?$/'],
            'costTravelMode' => ['nullable', 'in:'.implode(',', array_column(TravelMode::cases(), 'value'))],
            'reason' => [$this->activity->status->requiresAmendment() ? 'required' : 'nullable', 'string', 'max:1000'],
        ], ['costAmount.regex' => 'Enter an amount such as 12,500.00.'], ['costCategoryId' => 'cost type', 'costAmount' => 'amount']);

        if ($this->costParticipantId && ! $this->activity->participants()->whereKey($this->costParticipantId)->exists()) {
            $this->addError('costParticipantId', 'Choose someone on this activity.');

            return;
        }

        $line = $this->costId ? $this->activity->costs()->findOrFail($this->costId) : null;
        $returned = $this->attempt(fn () => $editor->savePlannedCost($this->activity, [
            'cost_category_id' => (int) $this->costCategoryId,
            'activity_participant_id' => $this->costParticipantId ? (int) $this->costParticipantId : null,
            'description' => $this->costDescription ?: null,
            'estimated_amount' => $this->costAmount,
            'travel_mode' => $this->costTravelMode ?: null,
        ], $this->user(), $line, $this->reason ?: null));

        if ($returned !== null) {
            $this->done('Planned cost saved.'.($returned === true ? ' The increase is beyond tolerance, so the activity is back with the CEO.' : ''));
        }
    }

    public function removeCost(int $id, ActivityEditor $editor): void
    {
        $line = $this->activity->costs()->findOrFail($id);

        if ($this->activity->status->requiresAmendment()) {
            $this->costId = $id;
            $this->toast('Removing a planned cost after submission needs a reason: edit the line to 0 and give the reason instead.', 'warning');

            return;
        }

        if ($this->attempt(fn () => $editor->removePlannedCost($line, $this->user())) !== null) {
            $this->done('Cost line removed.');
        }
    }

    public function openOverride(int $id): void
    {
        $line = $this->activity->costs()->findOrFail($id);
        $this->costId = $id;
        $this->overrideAmount = (string) $line->estimated_amount;
        $this->showOverride = true;
    }

    public function saveOverride(DsaCalculator $dsa): void
    {
        abort_unless($this->user()->can('reference.manage'), 403); // CB-01: the Chief of Staff overrides
        $this->validate([
            'overrideAmount' => ['required', 'regex:/^\d{1,3}(,?\d{3})*(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'max:500'],
        ], [], ['overrideAmount' => 'amount', 'reason' => 'justification']);

        $line = $this->activity->costs()->findOrFail($this->costId);
        if ($this->attempt(fn () => $dsa->override($line, $this->overrideAmount, $this->reason))) {
            $this->done('DSA overridden. The computed amount and your justification are kept.');
        }
    }

    public function recalculateDsa(DsaCalculator $dsa): void
    {
        abort_unless($this->user()->can('activities.manage'), 403);
        $warnings = $dsa->recalculate($this->activity);
        $this->done('DSA recalculated.', $warnings);
    }

    public function saveActual(int $id, ActivityEditor $editor): void
    {
        $line = $this->activity->costs()->findOrFail($id);
        $amount = trim((string) ($this->actuals[$id] ?? ''));

        if ($amount !== '' && ! preg_match('/^\d{1,3}(,?\d{3})*(\.\d{1,2})?$/', $amount)) {
            $this->addError("actuals.{$id}", 'Enter an amount such as 12,500.00.');

            return;
        }

        if ($this->attempt(fn () => $editor->recordActual($line, $amount === '' ? null : $amount, trim((string) ($this->financeRefs[$id] ?? '')) ?: null, $this->user()))) {
            $this->done('Actual recorded.');
        }
    }

    public function saveImprest(): void
    {
        abort_unless($this->user()->can('activities.execute'), 403);
        $this->validate([
            'imprestReference' => ['nullable', 'string', 'max:100'],
            'imprestStatus' => ['nullable', 'in:'.implode(',', array_column(ImprestStatus::cases(), 'value'))],
        ]);

        $this->activity->update(['imprest_reference' => $this->imprestReference ?: null, 'imprest_status' => $this->imprestStatus ?: null]);
        $this->done('Imprest details saved.');
    }

    // ── Locations, documents, directives ────────────────────────────────

    public function updatedLocConstituency(string $value): void
    {
        if ($value && $c = Constituency::find($value)) {
            $this->locCounty = (string) $c->county_id;
            $this->locRegion = (string) $c->region_id;
        }
    }

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
        ], $this->user()))) {
            $this->done('Location added.');
        }
    }

    public function removeLocation(int $id, ActivityEditor $editor): void
    {
        $location = ActivityLocation::query()->where('activity_id', $this->activity->id)->findOrFail($id);

        if ($this->attempt(fn () => $editor->removeLocation($location, $this->user()))) {
            $this->done('Location removed.');
        }
    }

    public function uploadDocument(DocumentStore $store): void
    {
        abort_unless($this->user()->can('activities.manage'), 403);
        abort_if($this->activity->status->isReadOnly(), 403);
        $this->validate(['document' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png', 'max:'.config('activities.max_document_kb')]]);

        $kind = $this->activity->documents()->where('kind', ActivityDocument::KIND_MEMO)->exists() ? ActivityDocument::KIND_OTHER : ActivityDocument::KIND_MEMO;
        $store->store($this->activity, $this->document, $kind, $this->user());
        $this->done($kind === ActivityDocument::KIND_MEMO ? 'Source document attached.' : 'Document attached.');
    }

    public function saveDirective(): void
    {
        abort_unless($this->user()->can('directives.manage'), 403);
        $this->validate([
            'directiveBody' => ['required', 'string', 'max:2000'],
            'directiveDepartment' => ['nullable', 'exists:departments,id'],
            'directiveDue' => ['nullable', 'date'],
        ], [], ['directiveBody' => 'directive']);

        Directive::create([
            'activity_id' => $this->activity->id,
            'body' => $this->directiveBody,
            'responsible_department_id' => $this->directiveDepartment ?: null,
            'due_on' => $this->directiveDue ?: null,
            'status' => Directive::STATUS_OPEN,
            'on_ceo_instruction' => ! $this->user()->can('activities.decide'),
            'issued_by' => $this->user()->id,
        ]);
        $this->done('Directive recorded.');
    }

    public function render(ActivityCosting $costing, ParticipantChecks $checks): View
    {
        $activity = $this->activity->load([
            'category', 'type', 'organisingDepartment', 'budgetLine', 'locations.region', 'locations.county', 'locations.constituency',
            'participants.staff.department', 'participants.designation', 'participants.department', 'costs.category', 'costs.participant.staff',
            'documents', 'decisions.recorder', 'decisions.document', 'directives.department', 'report.document', 'report.recorder',
            'statusHistory.user', 'amendments.user', 'approver', 'creator', 'submissionLink',
        ]);

        $conflicts = $checks->conflictsFor($activity);
        $fieldDays = $activity->participants->where('is_external', false)
            ->mapWithKeys(fn (ActivityParticipant $p) => [$p->id => $checks->fieldDayFlags($p->staff_id, $activity, $p->days_planned ?? $activity->days)]);

        $candidates = collect();
        if ($this->showAddStaff) {
            $onTeam = $activity->participants->pluck('staff_id')->filter()->all();
            $candidates = Staff::query()->active()->search($this->staffSearch)->whereNotIn('id', $onTeam)
                ->with('department')->orderBy('name')->limit(40)->get()
                ->map(fn (Staff $s) => ['staff' => $s, 'conflicts' => $checks->conflicts($s->id, $activity->start_date, $activity->end_date, $activity->id)]);
        }

        return view('livewire.activities.show', [
            'breakdown' => $costing->breakdown($activity),
            'byCategory' => $costing->byCategory($activity),
            'conflicts' => $conflicts,
            'fieldDays' => $fieldDays,
            'candidates' => $candidates,
            'costCategories' => CostCategory::query()->active()->ordered()->get(),
            'departments' => Department::query()->active()->ordered()->get(['id', 'name']),
            'regions' => Region::query()->orderBy('name')->get(['id', 'name']),
            'counties' => County::query()->orderBy('name')->get(['id', 'name']),
            'constituencies' => $this->locCounty ? Constituency::query()->where('county_id', $this->locCounty)->orderBy('name')->get(['id', 'name']) : collect(),
            'removing' => $this->participantId ? $activity->participants->firstWhere('id', $this->participantId) : null,
            'overriding' => $this->costId ? $activity->costs->firstWhere('id', $this->costId) : null,
            'user' => $this->user(),
        ])->title($activity->reference.' · '.$activity->title);
    }
}
