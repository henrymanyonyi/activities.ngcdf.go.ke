<?php

use App\Enums\ActivityStatus;
use App\Enums\DecisionMode;
use App\Enums\DecisionType;
use App\Enums\ParticipationStatus;
use App\Exceptions\ActivityWorkflowException;
use App\Models\ActivityStatusHistory;
use App\Models\CostCategory;
use App\Services\ActivityEditor;
use App\Services\ActivityLifecycle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

it('calculates end date and nights from the start and number of days (BR-01)', function () {
    $activity = capturedActivity(chiefOfStaff(), ['start_date' => '2026-11-02', 'days' => 3]);

    expect($activity->end_date->toDateString())->toBe('2026-11-04')
        ->and($activity->nights)->toBe(2)
        ->and($activity->status)->toBe(ActivityStatus::Draft)
        ->and($activity->reference)->toStartWith('FAPM-2026-27-');
});

it('flags late notice and retrospective activities (CD-05, CD-06)', function () {
    Storage::fake('local');
    $cos = chiefOfStaff();

    expect(capturedActivity($cos, ['start_date' => today()->addDay()->toDateString()])->is_late_notice)->toBeTrue()
        ->and(capturedActivity($cos, ['start_date' => today()->addDays(40)->toDateString()])->is_late_notice)->toBeFalse()
        ->and(capturedActivity($cos, ['start_date' => today()->subDays(3)->toDateString()])->is_retrospective)->toBeFalse();

    // Retrospective is about approval, not capture: approved after it started.
    $late = capturedActivity($cos, ['start_date' => today()->subDays(3)->toDateString()]);
    app(ActivityLifecycle::class)->recordDecision($late, DecisionType::Approved, null, 'M/R', today()->subDay(), UploadedFile::fake()->create('a.pdf'), $cos);
    $prior = capturedActivity($cos, ['start_date' => today()->subDays(3)->toDateString()]);
    app(ActivityLifecycle::class)->recordDecision($prior, DecisionType::Approved, null, 'M/P', today()->subDays(5), UploadedFile::fake()->create('a.pdf'), $cos);

    expect($late->fresh()->is_retrospective)->toBeTrue()
        ->and($prior->fresh()->is_retrospective)->toBeFalse();
});

it('runs the full lifecycle from draft to closed', function () {
    $cos = chiefOfStaff();
    $ceo = ceo();
    $lifecycle = app(ActivityLifecycle::class);
    $editor = app(ActivityEditor::class);

    $activity = capturedActivity($cos, ['start_date' => today()->toDateString(), 'days' => 2]);
    $editor->savePlannedCost($activity, ['cost_category_id' => CostCategory::where('code', 'travel')->value('id'), 'estimated_amount' => '3500'], $cos);

    $lifecycle->submitForDecision($activity, $cos);
    expect($activity->status)->toBe(ActivityStatus::AwaitingDecision);
    expect($ceo->unreadNotifications)->toHaveCount(1);

    $lifecycle->decide($activity, DecisionType::Approved, null, $ceo);
    expect($activity->status)->toBe(ActivityStatus::Approved)
        ->and($activity->approval_mode)->toBe(DecisionMode::InSystem)
        ->and($activity->approved_estimated_total)->not->toBeNull();

    $lifecycle->confirmCommencement($activity, assistant());
    expect($activity->status)->toBe(ActivityStatus::InProgress);

    $participant = $activity->participants()->first();
    $editor->recordAttendance($participant, ParticipationStatus::Attended, 2, $cos);

    $lifecycle->complete($activity, $cos);
    expect($activity->status)->toBe(ActivityStatus::Completed)
        ->and($activity->report_due_on)->not->toBeNull();

    $lifecycle->recordReport($activity, ['received_on' => today()->toDateString(), 'outputs_achieved' => 'All proposals reviewed'], null, $cos);
    expect($activity->status)->toBe(ActivityStatus::ReportReceived);

    expect(fn () => $lifecycle->close($activity, $cos))->toThrow(ActivityWorkflowException::class, 'actual amount');

    $activity->costs()->get()->each(fn ($line) => $editor->recordActual($line, '1000.00', 'IMP/1', $cos));
    expect($activity->fresh()->actual_total)->toBe('1000.00');
    $lifecycle->close($activity->fresh(), $cos);

    expect($activity->fresh()->status)->toBe(ActivityStatus::Closed)
        ->and(ActivityStatus::Closed->isReadOnly())->toBeTrue()
        ->and(ActivityStatusHistory::where('activity_id', $activity->id)->count())->toBe(7);
});

it('allows only the CEO to decide (BR-06, 4.1)', function () {
    $cos = chiefOfStaff();
    $activity = capturedActivity($cos);
    app(ActivityLifecycle::class)->submitForDecision($activity, $cos);

    app(ActivityLifecycle::class)->decide($activity, DecisionType::Approved, null, $cos);
})->throws(AuthorizationException::class);

it('requires a comment to decline or return (CD-02)', function () {
    $cos = chiefOfStaff();
    $activity = capturedActivity($cos);
    app(ActivityLifecycle::class)->submitForDecision($activity, $cos);

    app(ActivityLifecycle::class)->decide($activity, DecisionType::Returned, '  ', ceo());
})->throws(ActivityWorkflowException::class, 'comment is required');

it('records a decision given outside the system with memo and scan (CD-03)', function () {
    $cos = chiefOfStaff();
    $activity = capturedActivity($cos);

    $decision = app(ActivityLifecycle::class)->recordDecision(
        $activity, DecisionType::Approved, null, 'CEO/MEMO/12', today(), UploadedFile::fake()->create('signed.pdf', 30, 'application/pdf'), $cos,
    );

    expect($activity->status)->toBe(ActivityStatus::Approved)
        ->and($decision->mode)->toBe(DecisionMode::RecordedOnBehalf)
        ->and($decision->document_id)->not->toBeNull()
        ->and($activity->approval_reference)->toBe('CEO/MEMO/12');
});

it('does not let the Assistant Chief of Staff record outside decisions or cancel (4.1)', function () {
    $activity = capturedActivity(chiefOfStaff());
    $assistant = assistant();

    expect(fn () => app(ActivityLifecycle::class)->cancel($activity, 'x', $assistant))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ActivityLifecycle::class)->recordDecision($activity, DecisionType::Approved, null, 'M/1', today(), UploadedFile::fake()->create('a.pdf'), $assistant))
        ->toThrow(AuthorizationException::class);
});

it('will not submit without a source document (PL-04)', function () {
    $cos = chiefOfStaff();
    $activity = capturedActivity($cos);
    $activity->documents()->delete();

    app(ActivityLifecycle::class)->submitForDecision($activity, $cos);
})->throws(ActivityWorkflowException::class, 'source document');

it('only discards drafts; others are cancelled (CF-10)', function () {
    $cos = chiefOfStaff();
    $draft = capturedActivity($cos);
    app(ActivityLifecycle::class)->discardDraft($draft, $cos);
    expect($draft->fresh()->trashed())->toBeTrue();

    $submitted = capturedActivity($cos);
    app(ActivityLifecycle::class)->submitForDecision($submitted, $cos);
    expect(fn () => app(ActivityLifecycle::class)->discardDraft($submitted, $cos))->toThrow(ActivityWorkflowException::class);
});

it('returns an approved activity to the CEO when postponed beyond tolerance (EX-03, BR-10)', function () {
    $cos = chiefOfStaff();
    $activity = capturedActivity($cos);
    app(ActivityLifecycle::class)->recordDecision($activity, DecisionType::Approved, null, 'M/2', today(), UploadedFile::fake()->create('a.pdf'), $cos);

    app(ActivityLifecycle::class)->postpone($activity, 'Venue unavailable', $cos, $activity->start_date->copy()->addDay());
    expect($activity->status)->toBe(ActivityStatus::Approved);

    app(ActivityLifecycle::class)->postpone($activity, 'County elections', $cos, $activity->start_date->copy()->addDays(14));
    expect($activity->status)->toBe(ActivityStatus::AwaitingDecision)
        ->and($activity->amendments()->count())->toBe(2);
});

it('completes in-progress activities after their end date and sets the report due date (RP-01)', function () {
    $cos = chiefOfStaff();
    $activity = capturedActivity($cos, ['start_date' => today()->subDays(5)->toDateString(), 'days' => 2]);
    app(ActivityLifecycle::class)->recordDecision($activity, DecisionType::Approved, null, 'M/3', today()->subDays(6), UploadedFile::fake()->create('a.pdf'), $cos);
    app(ActivityLifecycle::class)->confirmCommencement($activity, $cos, today()->subDays(5));

    $this->artisan('activities:daily')->assertSuccessful();

    expect($activity->fresh()->status)->toBe(ActivityStatus::Completed)
        ->and($activity->fresh()->report_due_on)->not->toBeNull();
});
