<?php

namespace App\Livewire;

use App\Enums\ActivityStatus;
use App\Enums\ParticipantRole;
use App\Exceptions\ActivityWorkflowException;
use App\Http\Controllers\PrivacyStatementController;
use App\Models\Activity;
use App\Models\ActivityCategory;
use App\Models\ActivityType;
use App\Models\Constituency;
use App\Models\CostCategory;
use App\Models\County;
use App\Models\Staff;
use App\Models\SubmissionLink;
use App\Services\DelegatedMemos;
use App\Services\MemoDocument;
use App\Services\PrivacyConsentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The page an officer opens from a Head of Department's memo request
 * (App\Services\DelegatedMemos). It shows nothing but this one memo: first a
 * code sent to the officer's email or mobile, then the memo's details, team,
 * costs and supporting documents, a preview of the generated memo, and
 * submission to the Head. The token is re-checked on every request, and the
 * officer must have agreed to the Data Privacy Statement.
 */
#[Layout('layouts.public')]
#[Title('Prepare an activity memo')]
class MemoRequest extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $token = '';

    public string $code = '';

    /** @var list<string> where the last code went, masked */
    public array $codeSentTo = [];

    public string $title = '';

    public string $purpose = '';

    public string $expected_outputs = '';

    public string $justification = '';

    public string $risks = '';

    public string $target_audience = '';

    public string $notes = '';

    public string $activity_category_id = '';

    public string $activity_type_id = '';

    public string $start_date = '';

    public ?int $days = 1;

    public string $requested_budget = '';

    public string $county_id = '';

    public string $constituency_id = '';

    public string $venue = '';

    // Team
    public string $staffSearch = '';

    /** @var list<int> */
    public array $picked = [];

    public string $role = 'member';

    // A cost line
    public string $costCategory = '';

    public string $costParticipant = '';

    public string $costDescription = '';

    public string $costAmount = '';

    public $document = null;

    public bool $confirmSubmit = false;

    /** @var list<string> */
    public array $warnings = [];

    public ?string $done = null;

    public function mount(string $token, DelegatedMemos $memos): void
    {
        $this->token = $token;
        $link = $memos->find($token);

        if ($link?->isUsable() && ! app(PrivacyConsentService::class)->visitorHasConsented(request())) {
            session()->put(PrivacyStatementController::RETURN_TO_KEY, route('memo-request.show', $token));
            $this->redirectRoute('privacy.statement');

            return;
        }

        if ($link && $memos->isVerified($link)) {
            $this->fill($this->detailsOf($link->memo));
        }
    }

    private function link(): SubmissionLink
    {
        return app(DelegatedMemos::class)->find($this->token) ?? abort(404);
    }

    /** Runs an action on the link, showing its message on failure. */
    private function act(callable $action, ?string $done = null, string $field = 'form'): bool
    {
        try {
            $action();
            if ($done) {
                $this->dispatch('notify', message: $done, type: 'success');
            }

            return true;
        } catch (ActivityWorkflowException|AuthorizationException $e) {
            $this->addError($field, $e->getMessage());

            return false;
        }
    }

    // ── Confirming who they are ──────────────────────────────────────

    public function sendCode(DelegatedMemos $memos): void
    {
        $this->act(function () use ($memos) {
            $this->codeSentTo = $memos->sendCode($this->link());
        }, field: 'code');
    }

    public function verify(DelegatedMemos $memos): void
    {
        $this->validate(['code' => ['required', 'digits:6']], [], ['code' => 'code']);

        if ($this->act(fn () => $memos->verify($this->link(), $this->code), field: 'code')) {
            $this->code = '';
            $this->fill($this->detailsOf($this->link()->memo));
        }
    }

    // ── The memo ─────────────────────────────────────────────────────

    public function updatedCountyId(): void
    {
        $this->constituency_id = '';
    }

    public function updatedDays(): void
    {
        $this->saveDates();
    }

    public function updatedStartDate(): void
    {
        $this->saveDates();
    }

    /** Once the memo exists, a change of dates is saved at once so the DSA lines and the total are recalculated. */
    private function saveDates(): void
    {
        $link = $this->link();
        if (! $link->activity_id) {
            return;
        }

        $this->validate([
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'days' => ['required', 'integer', 'min:1', 'max:120'],
        ], [], ['days' => 'number of days']);

        $this->act(fn () => app(DelegatedMemos::class)->saveDates($link, $this->start_date, (int) $this->days), 'Dates saved. Allowances (DSA) and the total have been recalculated.', 'days');
    }

    public function save(DelegatedMemos $memos): void
    {
        $data = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'purpose' => ['required', 'string', 'max:5000'],
            'expected_outputs' => ['nullable', 'string', 'max:5000'],
            'justification' => ['required', 'string', 'max:5000'],
            'risks' => ['nullable', 'string', 'max:5000'],
            'target_audience' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'activity_category_id' => ['required', 'exists:activity_categories,id'],
            'activity_type_id' => ['nullable', 'exists:activity_types,id'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'days' => ['required', 'integer', 'min:1', 'max:120'],
            'requested_budget' => ['nullable', 'numeric', 'min:0', 'max:9999999999999', 'regex:/^\d+(\.\d{1,2})?$/'],
            'county_id' => ['nullable', 'exists:counties,id'],
            'constituency_id' => ['nullable', 'exists:constituencies,id'],
            'venue' => ['required', 'string', 'max:255'],
        ], [], ['purpose' => 'purpose', 'justification' => 'introduction and justification', 'activity_category_id' => 'category', 'target_audience' => 'participants and stakeholders', 'requested_budget' => 'estimated budget']);

        $this->act(fn () => $memos->saveDetails($this->link(), $data), 'Saved. Add the team and costs below, then preview the memo.');
    }

    public function addOfficers(DelegatedMemos $memos): void
    {
        Validator::make(['picked' => $this->picked, 'role' => $this->role], [
            'picked' => ['required', 'array', 'min:1'],
            'picked.*' => ['integer', 'exists:staff,id'],
            'role' => ['required', 'in:'.implode(',', array_column(ParticipantRole::cases(), 'value'))],
        ], ['picked.required' => 'Tick the officers to add.'])->validate();

        $this->act(function () use ($memos) {
            $this->warnings = $memos->addOfficers($this->link(), $this->picked, ParticipantRole::from($this->role));
            $this->reset(['picked', 'staffSearch']);
        }, 'Added to the team. Allowances (DSA) are calculated for you.', 'picked');
    }

    public function removeParticipant(DelegatedMemos $memos, int $id): void
    {
        $this->act(fn () => $memos->removeParticipant($this->link(), $id), 'Removed from the team.');
    }

    public function addCost(DelegatedMemos $memos): void
    {
        $this->validate([
            'costCategory' => ['required', 'exists:cost_categories,id'],
            'costParticipant' => ['nullable', 'integer'],
            'costDescription' => ['nullable', 'string', 'max:255'],
            'costAmount' => ['required', 'regex:/^\d{1,3}(,?\d{3})*(\.\d{1,2})?$/'],
        ], [], ['costCategory' => 'cost type', 'costAmount' => 'amount']);

        $memo = $this->link()->memo;
        if ($this->costParticipant !== '' && ! $memo?->participants()->whereKey((int) $this->costParticipant)->exists()) {
            $this->addError('costParticipant', 'Choose someone on the team, or the whole activity.');

            return;
        }

        $this->act(function () use ($memos) {
            $memos->saveCost($this->link(), [
                'cost_category_id' => (int) $this->costCategory,
                'activity_participant_id' => $this->costParticipant !== '' ? (int) $this->costParticipant : null,
                'description' => $this->costDescription ?: null,
                'estimated_amount' => $this->costAmount,
            ]);
            $this->reset(['costCategory', 'costParticipant', 'costDescription', 'costAmount']);
        }, 'Cost line added.', 'costAmount');
    }

    public function removeCost(DelegatedMemos $memos, int $id): void
    {
        $this->act(fn () => $memos->removeCost($this->link(), $id), 'Cost line removed.');
    }

    public function updatedDocument(DelegatedMemos $memos): void
    {
        $this->validate(['document' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png', 'max:'.config('activities.max_document_kb')]], [], ['document' => 'document']);

        if ($this->act(fn () => $memos->attach($this->link(), $this->document), 'Supporting document attached.', 'document')) {
            $this->reset('document');
        }
    }

    public function submit(DelegatedMemos $memos): void
    {
        $this->confirmSubmit = false;
        if ($this->act(fn () => $memos->submit($this->link()))) {
            $this->done = 'Submitted to your Head of Department, who will review it. You will be told by email or SMS if it comes back to you.';
        }
    }

    /** @return array<string, mixed> */
    private function detailsOf(?Activity $memo): array
    {
        if (! $memo) {
            return [];
        }

        $location = $memo->locations()->first();

        return [
            'title' => (string) $memo->title,
            'purpose' => (string) $memo->purpose,
            'expected_outputs' => (string) $memo->expected_outputs,
            'justification' => (string) $memo->justification,
            'risks' => (string) $memo->risks,
            'target_audience' => (string) $memo->target_audience,
            'notes' => (string) $memo->notes,
            'activity_category_id' => (string) $memo->activity_category_id,
            'activity_type_id' => (string) $memo->activity_type_id,
            'start_date' => $memo->start_date?->toDateString() ?? '',
            'days' => $memo->days,
            'requested_budget' => $memo->requested_budget !== null ? (string) (float) $memo->requested_budget : '',
            'county_id' => (string) $location?->county_id,
            'constituency_id' => (string) $location?->constituency_id,
            'venue' => (string) $location?->venue,
        ];
    }

    public function render(DelegatedMemos $memos, MemoDocument $document): View
    {
        $link = $memos->find($this->token);
        $verified = $link && $link->isUsable() && $memos->isVerified($link);
        $memo = $verified && $link->activity_id ? Activity::query()->with(['participants.staff', 'costs.category', 'costs.participant.staff', 'documents', 'locations'])->find($link->activity_id) : null;
        $editable = $verified && (! $memo || $memo->isWithPreparer());
        $search = trim($this->staffSearch);

        return view('livewire.memo-request', [
            'link' => $link?->loadMissing(['department', 'creator']),
            'usable' => $link?->isUsable() ?? false,
            'verified' => $verified,
            'memo' => $memo,
            'editable' => $editable,
            'returned' => $memo?->status === ActivityStatus::ReturnedToPreparer,
            'issues' => $memo && $editable ? $document->issues($memo) : [],
            'categories' => ActivityCategory::query()->active()->ordered()->get(['id', 'name']),
            'types' => $this->activity_category_id ? ActivityType::query()->active()->where('activity_category_id', $this->activity_category_id)->orderBy('name')->get(['id', 'name']) : collect(),
            'counties' => County::query()->orderBy('name')->get(['id', 'name']),
            'constituencies' => $this->county_id ? Constituency::query()->where('county_id', $this->county_id)->orderBy('name')->get(['id', 'name']) : collect(),
            'costCategories' => CostCategory::query()->active()->where('code', '!=', 'dsa')->ordered()->get(['id', 'name']),
            'roles' => ParticipantRole::cases(),
            'matches' => $memo && $editable && mb_strlen($search) >= 2
                ? Staff::query()->active()->search($search)->whereNotIn('id', $memo->participants->pluck('staff_id')->filter())->with(['department', 'designation'])->orderBy('name')->limit(20)->get()
                : collect(),
            'endDate' => $this->start_date && $this->days ? Carbon::parse($this->start_date)->addDays(max(1, (int) $this->days) - 1) : null,
        ]);
    }
}
