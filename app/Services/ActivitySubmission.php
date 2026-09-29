<?php

namespace App\Services;

use App\Exceptions\ActivityWorkflowException;
use App\Models\Activity;
use App\Models\ActivityDocument;
use App\Models\ActivityLocation;
use App\Models\CostCategory;
use App\Models\SubmissionLink;
use App\Notifications\ActivityAlert;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Turns a magic-link submission into a Draft activity. The Office of the CEO
 * stays in control: the Chief of Staff or Assistant reviews the draft and
 * submits it to the CEO like any other (FRD 1: information is captured by the
 * Office of the CEO). The submitter never sees anything else in the system.
 */
class ActivitySubmission
{
    public function __construct(
        private SubmissionLinkService $links,
        private ActivityEditor $editor,
        private ActivityLifecycle $lifecycle,
        private ParticipantListImporter $importer,
        private ActivityCosting $costing,
        private DocumentStore $documents,
        private AuditLogger $audit,
        private Alerts $alerts,
    ) {}

    /**
     * @param  array<string, mixed>  $details  validated activity fields (+ submitted_by_* and location)
     * @param  list<array<string, mixed>>  $participantRows  rows already validated by ParticipantListImporter
     * @param  list<array{cost_category_id: int|string, description: ?string, estimated_amount: string}>  $costLines
     */
    public function submit(SubmissionLink $link, array $details, array $participantRows, UploadedFile $memo, UploadedFile $participantList, array $costLines = []): Activity
    {
        if (! $this->links->consumeSubmission($link)) {
            throw new ActivityWorkflowException('This link is no longer accepting submissions.');
        }

        $submitter = trim((string) ($details['submitted_by_name'] ?? '')) ?: $link->recipient_name;

        return DB::transaction(function () use ($link, $details, $participantRows, $memo, $participantList, $costLines, $submitter) {
            $activity = $this->editor->create(collect($details)->only([
                'title', 'purpose', 'expected_outputs', 'notes', 'activity_category_id', 'activity_type_id',
                'organising_department_id', 'source_reference', 'source_received_on', 'start_date', 'days',
            ])->map(fn ($value) => $value === '' ? null : $value)->all() + [
                'submission_link_id' => $link->id,
                'submitted_by_name' => $submitter,
                'submitted_by_email' => $details['submitted_by_email'] ?? null,
                'submitted_by_phone' => $details['submitted_by_phone'] ?? null,
                'submitted_at' => now(),
            ], null, $submitter);

            if (! empty($details['county_id']) || ! empty($details['venue'])) {
                ActivityLocation::create([
                    'activity_id' => $activity->id,
                    'region_id' => ($details['region_id'] ?? null) ?: null,
                    'county_id' => ($details['county_id'] ?? null) ?: null,
                    'constituency_id' => ($details['constituency_id'] ?? null) ?: null,
                    'venue' => ($details['venue'] ?? null) ?: null,
                ]);
            }

            $this->importer->apply($activity, $participantRows);

            $valid = CostCategory::query()->active()->pluck('id')->all();
            foreach ($costLines as $line) {
                $cents = Money::toCents($line['estimated_amount'] ?? null);
                if ($cents <= 0 || ! in_array((int) $line['cost_category_id'], $valid, true)) {
                    continue;
                }

                $activity->costs()->create([
                    'cost_category_id' => (int) $line['cost_category_id'],
                    'description' => $line['description'] ?? null,
                    'estimated_amount' => Money::fromCents($cents),
                ]);
            }

            $this->documents->store($activity, $memo, ActivityDocument::KIND_MEMO, link: $link);
            $this->documents->store($activity, $participantList, ActivityDocument::KIND_PARTICIPANT_LIST, link: $link);

            $this->costing->refresh($activity);
            $this->audit->record($activity, 'submitted_via_link', new: ['submission_link_id' => $link->id], actorName: $submitter);
            $this->alerts->toStaffOfCeo(ActivityAlert::linkSubmission($activity));

            return $activity;
        });
    }
}
