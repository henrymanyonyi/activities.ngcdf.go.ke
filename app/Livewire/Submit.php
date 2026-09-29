<?php

namespace App\Livewire;

use App\Exceptions\ActivityWorkflowException;
use App\Models\ActivityCategory;
use App\Models\ActivityType;
use App\Models\Constituency;
use App\Models\CostCategory;
use App\Models\County;
use App\Models\Department;
use App\Models\SubmissionLink;
use App\Services\ActivitySubmission;
use App\Services\ParticipantListImporter;
use App\Services\SubmissionLinkService;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The page a link holder sees. It shows nothing but its own form; the token
 * is re-checked on every request, and the submission becomes a Draft for the
 * Office of the CEO to review.
 */
#[Layout('layouts.public')]
#[Title('Submit an activity')]
class Submit extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $token = '';

    public ?string $submittedReference = null;

    public string $title = '';

    public string $purpose = '';

    public string $expected_outputs = '';

    public string $activity_category_id = '';

    public string $activity_type_id = '';

    public string $organising_department_id = '';

    public string $source_reference = '';

    public string $start_date = '';

    /** Null by default, set in mount(); see Activities\Form::$days. */
    public ?int $days = null;

    public string $county_id = '';

    public string $constituency_id = '';

    public string $venue = '';

    public string $submitted_by_name = '';

    public string $submitted_by_email = '';

    public string $submitted_by_phone = '';

    public $memo = null;

    public $participantList = null;

    /** @var list<array{cost_category_id: string, description: string, estimated_amount: string}> */
    public array $costs = [];

    /** @var list<string> */
    public array $listErrors = [];

    public int $listRows = 0;

    public function mount(string $token, SubmissionLinkService $links): void
    {
        $this->token = $token;
        $this->days = 1;
        $link = $links->find($token);

        if ($link?->isUsable()) {
            $links->recordOpen($link);
            $this->submitted_by_name = $link->recipient_name;
            $this->submitted_by_email = (string) $link->recipient_email;
            $this->organising_department_id = (string) ($link->department_id ?? '');
            $this->activity_category_id = (string) ($link->activity_category_id ?? '');
        }
    }

    private function link(): ?SubmissionLink
    {
        $link = app(SubmissionLinkService::class)->find($this->token);

        return $link?->acceptsSubmissions() ? $link : null;
    }

    public function updatedParticipantList(ParticipantListImporter $importer): void
    {
        $this->validateOnly('participantList');
        $parsed = $importer->parse($this->participantList->getRealPath());
        $this->listErrors = $parsed['errors'];
        $this->listRows = count($parsed['rows']);
    }

    public function updatedCountyId(): void
    {
        $this->constituency_id = '';
    }

    public function addCost(): void
    {
        if (count($this->costs) < 10) {
            $this->costs[] = ['cost_category_id' => '', 'description' => '', 'estimated_amount' => ''];
        }
    }

    public function removeCost(int $i): void
    {
        unset($this->costs[$i]);
        $this->costs = array_values($this->costs);
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'purpose' => ['required', 'string', 'max:5000'],
            'expected_outputs' => ['nullable', 'string', 'max:5000'],
            'activity_category_id' => ['required', 'exists:activity_categories,id'],
            'activity_type_id' => ['nullable', 'exists:activity_types,id'],
            'organising_department_id' => ['required', 'exists:departments,id'],
            'source_reference' => ['nullable', 'string', 'max:100'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'days' => ['required', 'integer', 'min:1', 'max:120'],
            'county_id' => ['required', 'exists:counties,id'],
            'constituency_id' => ['nullable', 'exists:constituencies,id'],
            'venue' => ['nullable', 'string', 'max:255'],
            'submitted_by_name' => ['required', 'string', 'max:255'],
            'submitted_by_email' => ['nullable', 'email', 'max:255'],
            'submitted_by_phone' => ['nullable', 'string', 'max:30'],
            'memo' => ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:'.config('activities.max_document_kb')],
            'participantList' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:'.config('activities.max_list_kb')],
            'costs.*.cost_category_id' => ['required', 'exists:cost_categories,id'],
            'costs.*.description' => ['nullable', 'string', 'max:255'],
            'costs.*.estimated_amount' => ['required', 'regex:/^\d{1,3}(,?\d{3})*(\.\d{1,2})?$/'],
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return ['purpose' => 'objective', 'organising_department_id' => 'department', 'activity_category_id' => 'category', 'county_id' => 'county', 'memo' => 'memo', 'participantList' => 'participant list', 'submitted_by_name' => 'your name'];
    }

    public function submit(ActivitySubmission $submission, ParticipantListImporter $importer): void
    {
        $link = $this->link();
        abort_unless($link, 403);

        $data = $this->validate();
        $parsed = $importer->parse($this->participantList->getRealPath());
        if ($parsed['errors'] !== []) {
            $this->listErrors = $parsed['errors'];
            $this->addError('participantList', 'Fix the rows listed below, then upload the list again.');

            return;
        }

        $county = County::query()->find($data['county_id']);
        $constituency = $data['constituency_id'] ? Constituency::query()->find($data['constituency_id']) : null;

        try {
            $activity = $submission->submit($link, $data + [
                'region_id' => $constituency?->region_id ?? $county?->constituencies()->value('region_id'),
            ], $parsed['rows'], $this->memo, $this->participantList, $this->costs);
        } catch (ActivityWorkflowException $e) {
            $this->addError('title', $e->getMessage());

            return;
        }

        $this->submittedReference = $activity->reference;
    }

    public function render(): View
    {
        $link = app(SubmissionLinkService::class)->find($this->token);

        return view('livewire.submit', [
            'link' => $link,
            'usable' => $link?->acceptsSubmissions() ?? false,
            'categories' => ActivityCategory::query()->active()->ordered()->get(['id', 'name']),
            'types' => $this->activity_category_id ? ActivityType::query()->active()->where('activity_category_id', $this->activity_category_id)->orderBy('name')->get(['id', 'name']) : collect(),
            'departments' => Department::query()->active()->ordered()->get(['id', 'name']),
            'counties' => County::query()->orderBy('name')->get(['id', 'name']),
            'constituencies' => $this->county_id ? Constituency::query()->where('county_id', $this->county_id)->orderBy('name')->get(['id', 'name']) : collect(),
            'costCategories' => CostCategory::query()->active()->where('code', '!=', 'dsa')->ordered()->get(['id', 'name']),
            'endDate' => $this->start_date ? Carbon::parse($this->start_date)->addDays(max(1, (int) $this->days) - 1) : null,
        ]);
    }
}
