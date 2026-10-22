<?php

use App\Enums\ActivityStatus;
use App\Enums\DecisionType;
use App\Enums\ParticipantRole;
use App\Exceptions\ActivityWorkflowException;
use App\Livewire\Activities\Show;
use App\Models\Activity;
use App\Models\ActivityAmendment;
use App\Models\CostCategory;
use App\Models\DsaRate;
use App\Models\ParticipantRelease;
use App\Models\Staff;
use App\Models\User;
use App\Services\ActivityEditor;
use App\Services\ActivityLifecycle;
use App\Services\MemoDocument;
use App\Services\ParticipantChecks;
use App\Services\ParticipantReleases;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    DsaRate::create(['job_grade' => 'NGCDF 5', 'destination_category' => 'Other towns', 'amount' => '8400.00', 'effective_from' => '2020-07-01']);
    $this->cos = chiefOfStaff();
    $this->ceo = ceo();
    $this->editor = app(ActivityEditor::class);
    $this->lifecycle = app(ActivityLifecycle::class);
    $this->henry = Staff::factory()->create(['name' => 'Henry Wafula']);

    // Activity A: 11 days (like 21 to 31 October), approved, with Henry on it.
    $this->a = approvedActivity($this, ['title' => 'Activity A', 'start_date' => today()->addDays(30)->toDateString(), 'days' => 11], [$this->henry]);
});

/** @param  list<Staff>  $team */
function approvedActivity(object $test, array $overrides, array $team): Activity
{
    $activity = capturedActivity($test->cos, $overrides, $team);
    $test->lifecycle->forward($activity, $test->cos);
    $test->lifecycle->decide($activity->fresh(), DecisionType::Approved, null, $test->ceo);

    return $activity->fresh();
}

function day(int $offset): Carbon
{
    return today()->addDays($offset);
}

it('keeps an officer on an approved activity out of another activity on the same days, telling a Head of Department the days only', function () {
    $hod = headOfDepartment();
    $memo = departmentMemo($hod, ['start_date' => day(30)->toDateString(), 'days' => 11]);

    expect(fn () => $this->editor->addStaff($memo, [$this->henry->id], ParticipantRole::Member, $hod, [$this->henry->id => 'Needed on both']))
        ->toThrow(ActivityWorkflowException::class, 'Henry Wafula is not available: already on another approved activity from '.day(30)->format('d M Y').' to '.day(40)->format('d M Y').'. Choose someone else');

    // The Office of the CEO is told which activity, and may place him only with a reason.
    $b = capturedActivity($this->cos, ['title' => 'Activity B', 'start_date' => day(30)->toDateString(), 'days' => 11]);
    expect(fn () => $this->editor->addStaff($b, [$this->henry->id], ParticipantRole::Member, $this->cos))
        ->toThrow(ActivityWorkflowException::class, '('.$this->a->reference.'). Release them from it first, or give a reason to proceed.');

    $this->editor->addStaff($b, [$this->henry->id], ParticipantRole::Member, $this->cos, [$this->henry->id => 'Leads both teams on the same site']);
    expect($b->participants()->where('staff_id', $this->henry->id)->value('conflict_reason'))->toBe('Leads both teams on the same site');
});

it('frees the officer the day after the activity ends', function () {
    $checks = app(ParticipantChecks::class);

    expect($checks->unavailableStaff(day(40), day(45)))->toHaveKey($this->henry->id)
        ->and($checks->unavailableStaff(day(41), day(45)))->not->toHaveKey($this->henry->id);

    $hod = headOfDepartment();
    $next = departmentMemo($hod, ['start_date' => day(41)->toDateString(), 'days' => 3]);
    $this->editor->addStaff($next, [$this->henry->id], ParticipantRole::Member, $hod);

    expect($next->participants()->where('staff_id', $this->henry->id)->exists())->toBeTrue();
});

it('treats approved or later as blocking, pending memos as a warning, and drafts, declined and cancelled as free', function () {
    $checks = app(ParticipantChecks::class);
    $officer = Staff::factory()->create();
    $at = fn (int $from) => ['start_date' => day($from)->toDateString(), 'days' => 2];

    capturedActivity($this->cos, $at(60), [$officer]); // draft
    $pending = capturedActivity($this->cos, $at(62), [$officer]);
    $this->lifecycle->forward($pending, $this->cos); // awaiting decision
    $declined = capturedActivity($this->cos, $at(64), [$officer]);
    $this->lifecycle->forward($declined, $this->cos);
    $this->lifecycle->decide($declined->fresh(), DecisionType::Declined, 'Not now', $this->ceo);
    $cancelled = approvedActivity($this, $at(66), [$officer]);
    $this->lifecycle->cancel($cancelled, 'Called off', $this->cos);
    $postponed = approvedActivity($this, $at(68), [$officer]);
    $this->lifecycle->postpone($postponed, 'Await the county', $this->cos);

    expect(array_keys($checks->unavailableStaff(day(60), day(61))))->not->toContain($officer->id)
        ->and($checks->pendingStaff(day(62), day(63)))->toHaveKey($officer->id)
        ->and(array_keys($checks->unavailableStaff(day(62), day(63))))->not->toContain($officer->id)
        ->and(array_keys($checks->unavailableStaff(day(64), day(67))))->not->toContain($officer->id)
        ->and($checks->unavailableStaff(day(68), day(69)))->toHaveKey($officer->id);
});

it('re-checks availability at approval, so the second of two memos sharing an officer cannot be approved', function () {
    $officer = Staff::factory()->create(['name' => 'Grace Mwangi']);
    $first = capturedActivity($this->cos, ['start_date' => day(80)->toDateString(), 'days' => 3], [$officer]);
    $second = capturedActivity($this->cos, ['start_date' => day(81)->toDateString(), 'days' => 3], [$officer]);
    $this->lifecycle->forward($first, $this->cos);
    $this->lifecycle->forward($second, $this->cos);

    $this->lifecycle->decide($first->fresh(), DecisionType::Approved, null, $this->ceo);

    expect(fn () => $this->lifecycle->decide($second->fresh(), DecisionType::Approved, null, $this->ceo))
        ->toThrow(ActivityWorkflowException::class, 'Grace Mwangi is already on another approved activity on these dates.')
        ->and($second->fresh()->status)->toBe(ActivityStatus::AwaitingDecision);
});

it('blocks a Head of Department\'s submission when a date change puts a team member onto an approved activity', function () {
    $hod = headOfDepartment();
    $memo = departmentMemo($hod, ['start_date' => day(50)->toDateString(), 'days' => 2]);
    $this->editor->addStaff($memo, [$this->henry->id], ParticipantRole::Member, $hod);

    $this->editor->update($memo->fresh(), ['start_date' => day(39)->toDateString(), 'days' => 2], $hod);

    expect(app(MemoDocument::class)->blockingIssues($memo->fresh()))->toContain('Henry Wafula is not available: already on another approved activity on some of these days. Remove them or ask for their release.')
        ->and(fn () => $this->lifecycle->submitForReview($memo->fresh(), $hod))->toThrow(ActivityWorkflowException::class, 'Henry Wafula is not available');
});

it('shows the effect of a recall before it is confirmed: DSA reduced to the nights now spent, travel still payable', function () {
    $henry = $this->a->participants()->where('staff_id', $this->henry->id)->sole();
    $travel = CostCategory::query()->where('code', 'travel')->firstOrFail();
    $this->editor->savePlannedCost($this->a, ['cost_category_id' => $travel->id, 'activity_participant_id' => $henry->id, 'estimated_amount' => '6000'], $this->cos, amendmentReason: 'Bus fare');

    $preview = app(ParticipantReleases::class)->preview($henry->fresh(), day(35));

    expect($preview['days_before'])->toBe(11)
        ->and($preview['days_after'])->toBe(6)
        ->and($preview['available_from']->toDateString())->toBe(day(36)->toDateString())
        ->and($preview['before'])->toBe(8400_00 * 10 + 6000_00)
        ->and($preview['after'])->toBe(8400_00 * 5 + 6000_00)
        ->and($preview['change'])->toBe(-8400_00 * 5)
        ->and(collect($preview['lines'])->pluck('treatment')->all())->toBe([ParticipantRelease::LINE_REDUCED, ParticipantRelease::LINE_PAYABLE]);
});

it('records a recall without deleting the assignment, keeps the approved total, and frees the officer from the next day', function () {
    $henry = $this->a->participants()->where('staff_id', $this->henry->id)->sole();
    $approved = $this->a->approved_estimated_total;
    $acos = assistant();

    $release = app(ParticipantReleases::class)->release($henry, [
        'kind' => ParticipantRelease::KIND_RECALL,
        'reason' => 'Needed for the audit at headquarters',
        'last_day' => day(35),
        'initiated_by_name' => 'Director, Finance',
    ], $acos);

    $a = $this->a->fresh();
    expect($release)
        ->original_start_date->toDateString()->toBe(day(30)->toDateString())
        ->original_end_date->toDateString()->toBe(day(40)->toDateString())
        ->recorded_by->toBe($acos->id)
        ->participant_cost_before->toBe('84000.00')
        ->participant_cost_after->toBe('42000.00')
        ->and($henry->fresh())->end_date->toDateString()->toBe(day(35)->toDateString())->status->isExpected()->toBeTrue()
        ->and($a->approved_estimated_total)->toBe($approved)
        ->and($a->estimated_total)->toBe('42000.00')
        ->and($a->status)->toBe(ActivityStatus::Approved)
        ->and($a->amendments()->where('kind', ActivityAmendment::KIND_RELEASE)->sole()->reason)->toBe('Needed for the audit at headquarters');

    // Henry can now go on Activity B from day 36 (like 27 October).
    $hod = headOfDepartment();
    $b = departmentMemo($hod, ['start_date' => day(36)->toDateString(), 'days' => 3]);
    $this->editor->addStaff($b, [$this->henry->id], ParticipantRole::Member, $hod);
    expect($b->participants()->where('staff_id', $this->henry->id)->exists())->toBeTrue();
});

it('lets only the Office of the CEO release an officer, within the assignment, from an approved activity', function () {
    $henry = $this->a->participants()->where('staff_id', $this->henry->id)->sole();
    $data = ['kind' => ParticipantRelease::KIND_RELEASE, 'reason' => 'Leave', 'last_day' => day(33), 'initiated_by_name' => 'HOD'];
    $releases = app(ParticipantReleases::class);

    expect(fn () => $releases->release($henry, $data, headOfDepartment()))->toThrow(AuthorizationException::class)
        ->and(fn () => $releases->release($henry, ['last_day' => day(40)] + $data, $this->cos))->toThrow(ActivityWorkflowException::class, 'The last day must be from')
        ->and(fn () => $releases->release($henry, ['reason' => ' '] + $data, $this->cos))->toThrow(ActivityWorkflowException::class, 'Give the reason');

    $draft = capturedActivity($this->cos, ['start_date' => day(90)->toDateString(), 'days' => 3]);
    expect(fn () => $releases->release($draft->participants()->first(), ['last_day' => day(90)] + $data, $this->cos))
        ->toThrow(ActivityWorkflowException::class, 'only from an approved or in-progress activity');
});

it('adds a return ticket only with a reason, and sends the activity back to the CEO when the rise is beyond tolerance', function () {
    $henry = $this->a->participants()->where('staff_id', $this->henry->id)->sole();
    $data = [
        'kind' => ParticipantRelease::KIND_RECALL, 'reason' => 'Urgent', 'last_day' => day(39), 'initiated_by_name' => 'CEO',
        'extra' => ['amount' => '60000', 'cost_category_id' => CostCategory::query()->where('code', 'travel')->value('id'), 'description' => 'Return flight'],
    ];

    expect(fn () => app(ParticipantReleases::class)->release($henry, $data, $this->cos))->toThrow(ActivityWorkflowException::class, 'Say why the other costs change.');

    $release = app(ParticipantReleases::class)->release($henry, $data + ['revision_reason' => 'Flight back the same day'], $this->cos);

    expect(collect($release->lines)->firstWhere('treatment', ParticipantRelease::LINE_ADDED)['after'])->toBe(60000_00)
        ->and($this->a->fresh()->status)->toBe(ActivityStatus::AwaitingDecision)
        ->and($this->a->fresh()->amendments()->sole()->returned_for_decision)->toBeTrue();
});

it('reassigns the officer to an ongoing activity from the day after their last day', function () {
    $henry = $this->a->participants()->where('staff_id', $this->henry->id)->sole();
    $b = approvedActivity($this, ['title' => 'Activity B', 'start_date' => day(34)->toDateString(), 'days' => 5], [Staff::factory()->create()]);

    app(ParticipantReleases::class)->release($henry, [
        'kind' => ParticipantRelease::KIND_REASSIGNMENT, 'reason' => 'Needed on B', 'last_day' => day(35),
        'initiated_by_name' => 'Chief of Staff', 'destination_activity_id' => $b->id,
    ], $this->cos);

    $onB = $b->participants()->where('staff_id', $this->henry->id)->sole();
    expect($onB->startOn($b)->toDateString())->toBe(day(36)->toDateString())
        ->and($onB->endOn($b)->toDateString())->toBe(day(38)->toDateString())
        ->and($onB->conflict_reason)->toBeNull()
        ->and(app(ParticipantChecks::class)->conflictsFor($b->fresh()))->toBe([]);
});

it('records a recall from the team tab with the cost effect shown first', function () {
    $henry = $this->a->participants()->where('staff_id', $this->henry->id)->sole();

    Livewire::actingAs($this->cos)->test(Show::class, ['activity' => $this->a])
        ->call('setTab', 'team')
        ->call('openRelease', $henry->id)
        ->set('releaseLastDay', day(35)->toDateString())
        ->assertSee('Effect on the budget, before you confirm')
        ->assertSee('Reduced: DSA for the nights now spent')
        ->assertSee('Decrease KES 42,000.00')
        ->set('releaseInitiatedBy', 'Director, Finance')
        ->set('releaseReason', 'Recalled to the office')
        ->call('release')
        ->assertHasNoErrors();

    expect(ParticipantRelease::query()->sole()->last_day->toDateString())->toBe(day(35)->toDateString());

    Livewire::actingAs(User::factory()->ceo()->create())->test(Show::class, ['activity' => $this->a->fresh()])
        ->call('setTab', 'team')
        ->assertSee('Recalled to the office: last day '.day(35)->format('d M Y'));
});
