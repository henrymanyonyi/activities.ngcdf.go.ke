<?php

use App\Enums\ActivityStatus;
use App\Enums\DecisionType;
use App\Enums\ParticipantRole;
use App\Enums\ReviewOffice;
use App\Enums\ReviewRecommendation;
use App\Exceptions\ActivityWorkflowException;
use App\Models\Activity;
use App\Models\ActivityCategory;
use App\Models\ActivityReviewNote;
use App\Models\ActivityStatusHistory;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Staff;
use App\Models\User;
use App\Reports\Definitions\MemoWorkflow;
use App\Services\ActivityEditor;
use App\Services\ActivityLifecycle;
use App\Services\AppSettings;
use App\Services\MemoTracker;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->lifecycle = app(ActivityLifecycle::class);
    $this->editor = app(ActivityEditor::class);
    $this->ict = Department::query()->where('name', 'ICT')->firstOrFail();
    $this->hod = headOfDepartment($this->ict);
    $this->acos = assistant();
    $this->cos = chiefOfStaff();
    $this->ceo = ceo();
});

/** The reviewer adds the team the CEO needs to see before forwarding. */
function withTeam(Activity $memo, User $by): Activity
{
    app(ActivityEditor::class)->addStaff($memo, [Staff::factory()->create()->id], ParticipantRole::Member, $by);

    return $memo->fresh();
}

function alertKinds(User $user): array
{
    return $user->fresh()->notifications->pluck('data.kind')->all();
}

it('saves a Head of Department memo as a draft of their own department, whatever department is sent', function () {
    $other = Department::query()->where('name', '!=', 'ICT')->firstOrFail();

    $memo = departmentMemo($this->hod, ['organising_department_id' => $other->id, 'responsible_hod_id' => $this->cos->id]);

    expect($memo->status)->toBe(ActivityStatus::Draft)
        ->and($memo->organising_department_id)->toBe($this->ict->id)
        ->and($memo->responsible_hod_id)->toBe($this->hod->id)
        ->and($memo->created_by)->toBe($this->hod->id)
        ->and($memo->isDepartmentDraft())->toBeTrue()
        ->and($memo->isPreparedOnBehalf())->toBeFalse()
        ->and($memo->reference)->toStartWith('FAPM-');
});

it('lets the Head of Department edit a draft but not move it to another department', function () {
    $memo = departmentMemo($this->hod);
    $other = Department::query()->where('name', '!=', 'ICT')->firstOrFail();

    $this->editor->update($memo, ['title' => 'Revised title', 'justification' => 'Systems are due for review.', 'organising_department_id' => $other->id], $this->hod);

    expect($memo->fresh())
        ->title->toBe('Revised title')
        ->justification->toBe('Systems are due for review.')
        ->organising_department_id->toBe($this->ict->id);
});

it('asks for the introduction, venue and team before a Head of Department can submit, but no signed upload', function () {
    $memo = $this->editor->create(['title' => 'Review', 'purpose' => 'Review', 'activity_category_id' => ActivityCategory::query()->value('id'), 'start_date' => today()->addDays(10)->toDateString(), 'days' => 1], $this->hod);

    expect(fn () => $this->lifecycle->submitForReview($memo, $this->hod))->toThrow(ActivityWorkflowException::class,
        'Before submitting: Write the introduction and justification (section 1.0 of the memo). Add the venue or location. Add at least one officer to the team.');

    $this->editor->update($memo, ['justification' => 'Systems are due for review.'], $this->hod);
    $this->editor->addLocation($memo, ['venue' => 'Headquarters'], $this->hod);
    $this->editor->addStaff($memo, [Staff::factory()->create()->id], ParticipantRole::Member, $this->hod);

    // The generated memo stands as the memo: nothing is uploaded, and the reviewer can still forward it to the CEO.
    $this->lifecycle->submitForReview($memo->fresh(), $this->hod);
    expect($memo->fresh()->status)->toBe(ActivityStatus::UnderReview)
        ->and($memo->documents()->count())->toBe(0);

    $this->lifecycle->forward($memo->fresh(), $this->acos);
    expect($memo->fresh()->status)->toBe(ActivityStatus::AwaitingDecision);
});

it('submits the memo to the chosen reviewer, who is alerted, and locks it for the Head of Department', function () {
    $memo = departmentMemo($this->hod);

    $this->lifecycle->submitForReview($memo, $this->hod, ReviewOffice::AssistantChiefOfStaff, 'Please review before month end.');

    expect($memo->fresh())
        ->status->toBe(ActivityStatus::UnderReview)
        ->review_office->toBe(ReviewOffice::AssistantChiefOfStaff)
        ->approved_at->toBeNull()
        ->and(alertKinds($this->acos))->toBe(['submitted_for_review'])
        ->and(alertKinds($this->cos))->toBe([])
        ->and(ActivityStatusHistory::query()->where('activity_id', $memo->id)->orderByDesc('id')->value('reason'))->toBe('Submitted for review by the Assistant Chief of Staff: Please review before month end.');

    expect(fn () => $this->editor->update($memo->fresh(), ['title' => 'Changed in review'], $this->hod))
        ->toThrow(ActivityWorkflowException::class, 'This memo is with the Office of the CEO.');
});

it('refuses a duplicate submission from a stale page and records it once', function () {
    $memo = departmentMemo($this->hod);
    $stale = Activity::query()->findOrFail($memo->id);

    $this->lifecycle->submitForReview($memo, $this->hod);

    expect(fn () => $this->lifecycle->submitForReview($stale, $this->hod))->toThrow(ActivityWorkflowException::class, 'Someone else changed this memo')
        ->and(ActivityStatusHistory::query()->where('activity_id', $memo->id)->where('to_status', 'under_review')->count())->toBe(1);
});

it('returns the memo for correction with a mandatory reason, and takes the resubmission back to the same reviewer', function () {
    $memo = departmentMemo($this->hod);
    $this->lifecycle->submitForReview($memo, $this->hod, ReviewOffice::AssistantChiefOfStaff);

    expect(fn () => $this->lifecycle->returnForCorrection($memo, '  ', $this->acos))->toThrow(ActivityWorkflowException::class, 'Say what the Head of Department must correct.');

    $this->lifecycle->returnForCorrection($memo, 'Add the justification.', $this->acos);
    expect($memo->fresh())
        ->status->toBe(ActivityStatus::ReturnedToHod)
        ->status_reason->toBe('Add the justification.')
        ->and(alertKinds($this->hod))->toBe(['returned_for_correction']);

    $this->editor->update($memo->fresh(), ['justification' => 'The systems have not been reviewed this year.'], $this->hod);
    // Submitting to the Chief of Staff instead is ignored: it goes back to whoever returned it.
    $this->lifecycle->submitForReview($memo->fresh(), $this->hod, ReviewOffice::ChiefOfStaff, 'Justification added.');

    expect($memo->fresh())
        ->status->toBe(ActivityStatus::UnderReview)
        ->review_office->toBe(ReviewOffice::AssistantChiefOfStaff)
        ->justification->toBe('The systems have not been reviewed this year.')
        ->and($this->acos->fresh()->notifications->pluck('data.message')->implode('|'))->toContain('Corrected and resubmitted');
});

it('forwards a reviewed memo to the CEO with the recommendation kept, without approving it', function () {
    $memo = departmentMemo($this->hod);
    $this->lifecycle->submitForReview($memo, $this->hod);

    $memo = withTeam($memo, $this->acos);
    $where = $this->lifecycle->forward($memo, $this->acos, ReviewRecommendation::Approve, 'Well prepared.');

    expect($where)->toBe('the CEO')
        ->and($memo->fresh())
        ->status->toBe(ActivityStatus::AwaitingDecision)
        ->review_office->toBeNull()
        ->approved_at->toBeNull()
        ->approved_by->toBeNull()
        ->and($memo->reviewNotes()->sole())
        ->body->toBe('Well prepared.')
        ->recommendation->toBe(ReviewRecommendation::Approve)
        ->user_id->toBe($this->acos->id)
        ->and(alertKinds($this->ceo))->toBe(['awaiting_decision'])
        ->and(alertKinds($this->hod))->toBe(['memo_progressed']);
});

it('sends the Assistant\'s memos through the Chief of Staff when Settings say so', function () {
    app(AppSettings::class)->set('acos_forwards_via_cos', 1);
    $memo = withTeam(departmentMemo($this->hod), $this->cos);
    $this->lifecycle->submitForReview($memo, $this->hod);

    expect($this->lifecycle->forward($memo->fresh(), $this->acos))->toBe('the Chief of Staff')
        ->and($memo->fresh())->status->toBe(ActivityStatus::UnderReview)->review_office->toBe(ReviewOffice::ChiefOfStaff)
        ->and(alertKinds($this->cos))->toBe(['forwarded_for_review']);

    // The Assistant can no longer act on it; the Chief of Staff forwards it on.
    expect(fn () => $this->lifecycle->forward($memo->fresh(), $this->acos))->toThrow(ActivityWorkflowException::class, 'This memo is with the Chief of Staff')
        ->and(fn () => $this->lifecycle->returnForCorrection($memo->fresh(), 'x', $this->acos))->toThrow(ActivityWorkflowException::class, 'This memo is with the Chief of Staff');

    expect($this->lifecycle->forward($memo->fresh(), $this->cos))->toBe('the CEO')
        ->and($memo->fresh()->status)->toBe(ActivityStatus::AwaitingDecision);
});

it('lets the Assistant forward straight to the CEO by default', function () {
    $memo = withTeam(departmentMemo($this->hod), $this->cos);
    $this->lifecycle->submitForReview($memo, $this->hod);

    expect($this->lifecycle->forward($memo->fresh(), $this->acos))->toBe('the CEO');
});

it('records the CEO\'s approval and authorisation with who and when, and tells the Head of Department', function () {
    $memo = withTeam(departmentMemo($this->hod), $this->acos);
    $this->lifecycle->submitForReview($memo, $this->hod);
    $this->lifecycle->forward($memo->fresh(), $this->acos);

    $this->lifecycle->decide($memo->fresh(), DecisionType::Approved, 'Proceed.', $this->ceo);

    expect($memo->fresh())
        ->status->toBe(ActivityStatus::Approved)
        ->approved_by->toBe($this->ceo->id)
        ->approved_at->not->toBeNull()
        ->and($memo->decisions()->sole())->recorded_by->toBe($this->ceo->id)->comment->toBe('Proceed.')
        ->and($this->hod->fresh()->notifications->pluck('data.message')->all())->toContain('Approved and authorised by the CEO: '.$memo->title)
        ->and(ActivityStatusHistory::query()->where('activity_id', $memo->id)->orderBy('id')->pluck('to_status')->map->value->all())
        ->toBe(['draft', 'under_review', 'awaiting_decision', 'approved']);
});

it('needs a reason to decline, and a CEO return can go back to the Head of Department', function () {
    $memo = withTeam(departmentMemo($this->hod), $this->acos);
    $this->lifecycle->submitForReview($memo, $this->hod);
    $this->lifecycle->forward($memo->fresh(), $this->acos);

    expect(fn () => $this->lifecycle->decide($memo->fresh(), DecisionType::Declined, '', $this->ceo))->toThrow(ActivityWorkflowException::class);

    $this->lifecycle->decide($memo->fresh(), DecisionType::Returned, 'Clarify the participants.', $this->ceo);
    expect($memo->fresh()->status)->toBe(ActivityStatus::Returned);

    $this->lifecycle->returnForCorrection($memo->fresh(), 'The CEO asks which departments attend.', $this->cos);
    expect($memo->fresh())->status->toBe(ActivityStatus::ReturnedToHod)->review_office->toBe(ReviewOffice::ChiefOfStaff);

    $this->lifecycle->submitForReview($memo->fresh(), $this->hod);
    $this->lifecycle->forward($memo->fresh(), $this->cos);
    $this->lifecycle->decide($memo->fresh(), DecisionType::Declined, 'Not this quarter.', $this->ceo);

    expect($memo->fresh())->status->toBe(ActivityStatus::Declined)->status_reason->toBe('Not this quarter.');
});

it('records a memo prepared on a Head of Department\'s behalf with the real preparer, and it skips department submission', function () {
    $memo = capturedActivity($this->acos, [
        'organising_department_id' => $this->ict->id,
        'responsible_hod_id' => $this->hod->id,
        'responsible_hod_name' => $this->hod->name,
    ]);

    expect($memo)
        ->created_by->toBe($this->acos->id)
        ->responsible_hod_id->toBe($this->hod->id)
        ->and($memo->isPreparedOnBehalf())->toBeTrue()
        ->and($memo->isDepartmentDraft())->toBeFalse()
        ->and($memo->isVisibleTo($this->hod))->toBeTrue();

    expect($this->lifecycle->forward($memo, $this->acos))->toBe('the CEO')
        ->and(alertKinds($this->hod))->toBe(['memo_progressed']);

    // The Head of Department cannot change it once it is with the CEO.
    expect(fn () => $this->editor->update($memo->fresh(), ['title' => 'x'], $this->hod))->toThrow(ActivityWorkflowException::class);
});

it('keeps a Head of Department\'s unsubmitted draft away from the reviewers\' forward', function () {
    $memo = withTeam(departmentMemo($this->hod), $this->cos);

    expect(fn () => $this->lifecycle->forward($memo, $this->cos))->toThrow(ActivityWorkflowException::class, 'The Head of Department has not submitted this memo yet.');
});

it('refuses another department\'s Head of Department at every step', function () {
    $memo = departmentMemo($this->hod);
    $outsider = headOfDepartment(Department::query()->where('name', '!=', 'ICT')->firstOrFail());

    expect($memo->isVisibleTo($outsider))->toBeFalse()
        ->and(Activity::query()->visibleTo($outsider)->count())->toBe(0)
        ->and(Activity::query()->visibleTo($this->hod)->count())->toBe(1)
        ->and(fn () => $this->editor->update($memo, ['title' => 'x'], $outsider))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->lifecycle->submitForReview($memo, $outsider))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->lifecycle->discardDraft($memo, $outsider))->toThrow(AuthorizationException::class);
});

it('keeps each role to its own step', function (string $step) {
    $memo = withTeam(departmentMemo($this->hod), $this->cos);
    $this->lifecycle->submitForReview($memo, $this->hod);
    $memo->refresh();
    $admin = User::factory()->admin()->create();

    $attempt = match ($step) {
        'hod forwards' => fn () => $this->lifecycle->forward($memo, $this->hod),
        'hod returns' => fn () => $this->lifecycle->returnForCorrection($memo, 'x', $this->hod),
        'hod comments' => fn () => $this->lifecycle->addReviewNote($memo, 'x', null, $this->hod),
        'reviewer decides' => fn () => $this->lifecycle->decide($memo, DecisionType::Approved, null, $this->cos),
        'admin forwards' => fn () => $this->lifecycle->forward($memo, $admin),
        'admin returns' => fn () => $this->lifecycle->returnForCorrection($memo, 'x', $admin),
    };

    expect($attempt)->toThrow(AuthorizationException::class)
        ->and($memo->fresh()->status)->toBe(ActivityStatus::UnderReview);
})->with(['hod forwards', 'hod returns', 'hod comments', 'reviewer decides', 'admin forwards', 'admin returns']);

it('cannot be approved by the CEO before it is forwarded', function () {
    $memo = withTeam(departmentMemo($this->hod), $this->cos);
    $this->lifecycle->submitForReview($memo, $this->hod);

    expect(fn () => $this->lifecycle->decide($memo->fresh(), DecisionType::Approved, null, $this->ceo))
        ->toThrow(ActivityWorkflowException::class, 'Only an activity awaiting decision can be decided.');
});

it('accepts only one of two simultaneous CEO decisions', function () {
    $memo = withTeam(departmentMemo($this->hod), $this->cos);
    $this->lifecycle->submitForReview($memo, $this->hod);
    $this->lifecycle->forward($memo->fresh(), $this->cos);
    $first = Activity::query()->findOrFail($memo->id);
    $second = Activity::query()->findOrFail($memo->id);

    $this->lifecycle->decide($first, DecisionType::Approved, null, $this->ceo);

    expect(fn () => $this->lifecycle->decide($second, DecisionType::Declined, 'No', $this->ceo))->toThrow(ActivityWorkflowException::class)
        ->and($memo->fresh()->status)->toBe(ActivityStatus::Approved)
        ->and($memo->decisions()->count())->toBe(1);
});

it('keeps review comments as written, audited and impossible to edit or delete', function () {
    $memo = departmentMemo($this->hod);
    $this->lifecycle->submitForReview($memo, $this->hod);

    $note = $this->lifecycle->addReviewNote($memo->fresh(), 'Budget looks high.', ReviewRecommendation::Revise, $this->acos);
    $note->update(['body' => 'Rewritten']);
    $note->delete();

    expect(ActivityReviewNote::query()->sole()->body)->toBe('Budget looks high.')
        ->and(AuditLog::query()->where('auditable_type', $note->getMorphClass())->where('auditable_id', $note->id)->pluck('event')->all())->toBe(['created']);
});

it('counts each memo once at its stage, with budgets and stage durations', function () {
    $tracker = app(MemoTracker::class);

    $returned = departmentMemo($this->hod, ['requested_budget' => '100000.00']);
    $this->lifecycle->submitForReview($returned, $this->hod);
    $this->travel(2)->days();
    $this->lifecycle->returnForCorrection($returned->fresh(), 'Fix it', $this->acos);
    $this->travel(1)->days();
    $this->lifecycle->submitForReview($returned->fresh(), $this->hod);

    $stages = $tracker->stageDurations($returned->fresh()->statusHistory);
    expect($stages['review'])->toBe(2.0)
        ->and($stages['department'])->toBe(1.0)
        ->and($stages['submitted_at']->toDateString())->toBe(today()->subDays(3)->toDateString());

    $approved = withTeam(departmentMemo($this->hod, ['requested_budget' => '40000.00']), $this->cos);
    $this->lifecycle->submitForReview($approved, $this->hod);
    $this->lifecycle->forward($approved->fresh(), $this->cos);
    $this->travel(1)->days();
    $this->lifecycle->decide($approved->fresh(), DecisionType::Approved, null, $this->ceo);

    departmentMemo($this->hod); // a draft

    $summary = $tracker->summary(Activity::query());
    $approvedCents = Money::toCents($approved->fresh()->approved_estimated_total);

    expect($summary)->toMatchArray([
        'originated' => 3,
        'with_department' => 1,
        'with_acos' => 1,
        'awaiting_ceo' => 0,
        'approved' => 1,
        'ever_returned' => 1,
        'proposed_cents' => 100000_00 + 250000_00,
        'approved_cents' => $approvedCents,
    ]);

    expect($tracker->counts($this->hod))->toMatchArray(['drafts' => 1, 'pending' => 1, 'approved' => 1, 'returned' => 0, 'all' => 3])
        ->and($tracker->counts($this->acos))->toMatchArray(['mine' => 1, 'forwarded' => 0])
        ->and($tracker->actionCount($this->acos))->toBe(1)
        ->and($tracker->actionCount($this->cos))->toBe(0);

    $rows = app(MemoWorkflow::class)->rows([]);
    expect($rows)->toHaveCount(3)
        ->and($rows->firstWhere('_activity_id', $returned->id)['review_days'])->toBe('3.0');
});

it('flags a memo pending beyond the reminder period and alerts the reviewer daily', function () {
    $memo = departmentMemo($this->hod);
    $this->lifecycle->submitForReview($memo, $this->hod);
    $this->acos->notifications()->delete();

    $this->travel(10)->days();
    $this->artisan('activities:daily')->assertSuccessful();

    expect(alertKinds($this->acos))->toBe(['memo_pending'])
        ->and(app(MemoTracker::class)->summary(Activity::query())['pending_too_long'])->toBe(1);
});
