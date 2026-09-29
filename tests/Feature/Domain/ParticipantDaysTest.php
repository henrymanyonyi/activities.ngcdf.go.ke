<?php

use App\Enums\DecisionType;
use App\Exceptions\ActivityWorkflowException;
use App\Livewire\Activities\Show;
use App\Models\Activity;
use App\Models\DsaRate;
use App\Models\Staff;
use App\Services\ActivityEditor;
use App\Services\ActivityLifecycle;
use App\Services\Analytics;
use App\Services\ParticipantChecks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    DsaRate::create(['job_grade' => 'NGCDF 5', 'destination_category' => 'Other towns', 'amount' => '8400.00', 'effective_from' => '2020-07-01']);
});

function longActivity(?array $team = null, int $offset = 30): Activity
{
    return capturedActivity(chiefOfStaff(), ['start_date' => today()->addDays($offset)->toDateString(), 'days' => 35], $team);
}

it('lets each participant take part on only some days, with DSA on their own nights', function () {
    $activity = longActivity();
    $p = $activity->participants()->first();

    expect($p->plannedDays($activity))->toBe(35)
        ->and($activity->costs()->where('is_computed', true)->sole()->estimated_amount)->toBe('285600.00'); // 34 nights

    app(ActivityEditor::class)->setParticipantDates($p, $activity->start_date->copy()->addDays(4), $activity->start_date->copy()->addDays(8), chiefOfStaff());

    $p->refresh();
    expect($p->plannedDays($activity))->toBe(5)
        ->and($p->isPartial($activity))->toBeTrue()
        ->and($activity->costs()->where('is_computed', true)->sole()->estimated_amount)->toBe('33600.00') // 4 nights
        ->and($activity->fresh()->days)->toBe(35);
});

it('keeps a participant inside the activity dates', function () {
    $activity = longActivity();
    $p = $activity->participants()->first();

    app(ActivityEditor::class)->setParticipantDates($p, $activity->start_date->copy()->subDay(), $activity->start_date->copy()->addDay(), chiefOfStaff());
})->throws(ActivityWorkflowException::class, 'within the activity');

it('treats the full span as the whole activity', function () {
    $activity = longActivity();
    $p = $activity->participants()->first();
    app(ActivityEditor::class)->setParticipantDates($p, $activity->start_date, $activity->end_date, chiefOfStaff());

    expect($p->fresh()->start_date)->toBeNull()->and($p->fresh()->days_planned)->toBeNull();
});

it('only reports an overlap when the officer\'s own days overlap', function () {
    $cos = chiefOfStaff();
    $officer = Staff::factory()->create();
    $first = longActivity([$officer]);
    $p = $first->participants()->first();
    app(ActivityEditor::class)->setParticipantDates($p, $first->start_date, $first->start_date->copy()->addDays(2), $cos);
    app(ActivityLifecycle::class)->recordDecision($first, DecisionType::Approved, null, 'M/1', today(), UploadedFile::fake()->create('a.pdf'), $cos);

    $checks = app(ParticipantChecks::class);
    // Same activity window, but after the officer's three days: no conflict.
    expect($checks->conflicts($officer->id, $first->start_date->copy()->addDays(10), $first->start_date->copy()->addDays(12)))->toBeEmpty()
        ->and($checks->conflicts($officer->id, $first->start_date->copy()->addDay(), $first->start_date->copy()->addDays(5)))->toHaveCount(1);
});

it('counts only the officer\'s own days in field days and in the field today', function () {
    $cos = chiefOfStaff();
    $activity = longActivity(null, -5); // started 5 days ago
    $p = $activity->participants()->first();
    app(ActivityEditor::class)->setParticipantDates($p, $activity->start_date, $activity->start_date->copy()->addDays(2), $cos);
    app(ActivityLifecycle::class)->recordDecision($activity, DecisionType::Approved, null, 'M/2', today()->subDays(6), UploadedFile::fake()->create('a.pdf'), $cos);

    expect(app(Analytics::class)->inTheFieldToday())->toBeEmpty() // their three days are over
        ->and(app(ParticipantChecks::class)->fieldDays($p->staff_id, today()->subYear(), today()->addYear())['planned'])->toBe(3);
});

it('records a change of days after approval as an amendment with a reason', function () {
    $cos = chiefOfStaff();
    $activity = longActivity();
    app(ActivityLifecycle::class)->recordDecision($activity, DecisionType::Approved, null, 'M/3', today(), UploadedFile::fake()->create('a.pdf'), $cos);
    $p = $activity->participants()->first();
    $editor = app(ActivityEditor::class);

    expect(fn () => $editor->setParticipantDates($p, $activity->start_date, $activity->start_date->copy()->addDays(6), $cos))
        ->toThrow(ActivityWorkflowException::class, 'reason');

    $returned = $editor->setParticipantDates($p, $activity->start_date, $activity->start_date->copy()->addDays(6), $cos, 'Needed for the first week only');

    expect($returned)->toBeFalse() // cost went down: stays approved
        ->and($activity->amendments()->first()->summary)->toContain('(7 days)');
});

it('moves participants\' own dates when the activity is postponed', function () {
    $cos = chiefOfStaff();
    $activity = longActivity();
    $p = $activity->participants()->first();
    $editor = app(ActivityEditor::class);
    $editor->setParticipantDates($p, $activity->start_date->copy()->addDays(10), $activity->start_date->copy()->addDays(12), $cos);
    app(ActivityLifecycle::class)->recordDecision($activity, DecisionType::Approved, null, 'M/4', today(), UploadedFile::fake()->create('a.pdf'), $cos);

    $newStart = $activity->start_date->copy()->addDays(7);
    app(ActivityLifecycle::class)->postpone($activity, 'Venue', $cos, $newStart);

    expect($p->fresh()->start_date->toDateString())->toBe($newStart->copy()->addDays(10)->toDateString())
        ->and($p->fresh()->plannedDays($activity->fresh()))->toBe(3);
});

it('changes a participant\'s days from the team tab by first day and number of days', function () {
    $cos = chiefOfStaff();
    $activity = longActivity();
    $p = $activity->participants()->first();
    $day = fn (int $n) => $activity->start_date->copy()->addDays($n)->toDateString();

    Livewire::actingAs($cos)->test(Show::class, ['activity' => $activity])
        ->call('openDays', $p->id)
        ->assertSet('daysStart', $day(0))
        ->assertSet('daysCount', 35)
        ->set('daysStart', $day(20))
        ->set('daysCount', 20) // would end after the activity
        ->assertSee('Runs past the activity')
        ->call('saveDays')
        ->assertHasErrors('daysCount')
        ->set('daysCount', '')
        ->call('saveDays')
        ->assertHasErrors('daysCount')
        ->set('daysCount', 3)
        ->assertSee(Carbon::parse($day(22))->format('d M Y'))
        ->call('saveDays')
        ->assertHasNoErrors();

    expect($p->fresh()->plannedDays($activity))->toBe(3)
        ->and($p->fresh()->end_date->toDateString())->toBe($day(22));
});
