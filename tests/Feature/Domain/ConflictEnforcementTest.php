<?php

use App\Enums\DecisionType;
use App\Enums\ParticipantRole;
use App\Exceptions\ActivityWorkflowException;
use App\Livewire\Activities\Show;
use App\Models\Activity;
use App\Models\Staff;
use App\Services\ActivityEditor;
use App\Services\ActivityLifecycle;
use App\Services\ParticipantChecks;
use App\Support\FinancialYear;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->cos = chiefOfStaff();
    $this->officer = Staff::factory()->create(['name' => 'Peter Otieno']);
    $this->lifecycle = app(ActivityLifecycle::class);
    $this->editor = app(ActivityEditor::class);

    // An approved activity holding the officer for days 30 to 32.
    $this->first = capturedActivity($this->cos, ['start_date' => today()->addDays(30)->toDateString(), 'days' => 3], [$this->officer]);
    $this->lifecycle->recordDecision($this->first, DecisionType::Approved, null, 'M/1', today(), UploadedFile::fake()->create('a.pdf'), $this->cos);
});

/** A second draft with the officer, added before the first was approved (so no reason was asked for). */
function overlappingDraft(object $test): Activity
{
    $second = capturedActivity($test->cos, ['start_date' => today()->addDays(31)->toDateString(), 'days' => 2], []);
    $test->editor->addStaff($second, [$test->officer->id], ParticipantRole::Member, $test->cos, [$test->officer->id => 'placeholder']);
    $second->participants()->update(['conflict_reason' => null]);

    return $second->fresh();
}

it('refuses an approval recorded on the CEO\'s behalf while an overlap has no reason', function () {
    $second = overlappingDraft($this);

    expect(fn () => $this->lifecycle->recordDecision($second, DecisionType::Approved, null, 'M/2', today(), UploadedFile::fake()->create('b.pdf'), $this->cos))
        ->toThrow(ActivityWorkflowException::class, 'Peter Otieno is already on another approved activity');

    expect($second->fresh()->status->value)->toBe('draft')
        ->and($second->documents()->count())->toBe(1); // only the memo; the decision scan was not stored
});

it('refuses an approval in the system when an overlap appeared after submission', function () {
    $second = capturedActivity($this->cos, ['start_date' => today()->addDays(40)->toDateString(), 'days' => 2], [$this->officer]);
    $this->lifecycle->submitForDecision($second, $this->cos);

    // Rescheduled onto the officer's approved days while awaiting decision.
    $this->lifecycle->postpone($second->fresh(), 'Venue change', $this->cos, today()->addDays(31), 2);

    expect(fn () => $this->lifecycle->decide($second->fresh(), DecisionType::Approved, null, ceo()))
        ->toThrow(ActivityWorkflowException::class, 'give a reason for each before approving');
});

it('lets the overlap be explained afterwards, which unblocks the approval', function () {
    $second = overlappingDraft($this);
    $participant = $second->participants()->sole();

    Livewire::actingAs($this->cos)->test(Show::class, ['activity' => $second])
        ->set('tab', 'team')
        ->assertSee('no reason given')
        ->call('openExplain', $participant->id)
        ->call('explainConflict')
        ->assertHasErrors('overlapReason')
        ->set('overlapReason', 'Joins for the last day only, on the CEO\'s instruction')
        ->call('explainConflict')
        ->assertHasNoErrors();

    expect($participant->fresh()->conflict_reason)->toBe('Joins for the last day only, on the CEO\'s instruction');

    $this->lifecycle->recordDecision($second->fresh(), DecisionType::Approved, null, 'M/2', today(), UploadedFile::fake()->create('b.pdf'), $this->cos);
    expect($second->fresh()->status->value)->toBe('approved');
});

it('will not take a reason for someone with no overlap', function () {
    $free = capturedActivity($this->cos, ['start_date' => today()->addDays(60)->toDateString()]);

    expect(fn () => $this->editor->explainConflict($free->participants()->sole(), 'Because', $this->cos))
        ->toThrow(ActivityWorkflowException::class, 'no overlap');
});

it('asks for a reason when one person\'s days are moved onto an overlap', function () {
    $other = Staff::factory()->create(['name' => 'Amina Hassan']);
    $long = capturedActivity($this->cos, ['start_date' => today()->addDays(25)->toDateString(), 'days' => 10], [$other]);
    $this->editor->addStaff($long, [$this->officer->id], ParticipantRole::Member, $this->cos, [$this->officer->id => 'placeholder']);
    $participant = $long->participants()->where('staff_id', $this->officer->id)->sole();
    $participant->update(['conflict_reason' => null]);

    // Days 25 to 27 are clear of the approved activity (days 30 to 32).
    $this->editor->setParticipantDates($participant, today()->addDays(25), today()->addDays(27), $this->cos);

    expect(fn () => $this->editor->setParticipantDates($participant->fresh(), today()->addDays(29), today()->addDays(31), $this->cos))
        ->toThrow(ActivityWorkflowException::class, 'Peter Otieno is not available: already on another approved activity from '.today()->addDays(30)->format('d M Y').' to '.today()->addDays(32)->format('d M Y').' ('.$this->first->reference.')');

    $this->editor->setParticipantDates($participant->fresh(), today()->addDays(29), today()->addDays(31), $this->cos, conflictReason: 'Covers both sites');
    expect($participant->fresh()->conflict_reason)->toBe('Covers both sites');
});

it('shows each officer\'s field days this year when choosing a team, and can put the fewest first', function () {
    $busy = $this->officer; // 3 approved days
    $idle = Staff::factory()->create(['name' => 'Zainab Idle', 'department_id' => $busy->department_id]);
    $draft = capturedActivity($this->cos, ['start_date' => today()->addDays(60)->toDateString()], []);

    $fy = FinancialYear::for($draft->start_date);
    $days = app(ParticipantChecks::class)->plannedFieldDaysByStaff($fy->start(), $fy->end(), $draft->id);
    expect($days[$busy->id] ?? 0)->toBe(FinancialYear::for($this->first->start_date)->label() === $fy->label() ? 3 : 0)
        ->and($days[$idle->id] ?? 0)->toBe(0);

    Livewire::actingAs($this->cos)->test(Show::class, ['activity' => $draft])
        ->set('showAddStaff', true)
        ->assertSee('0 field days in '.$fy->label())
        ->set('staffSort', 'fewest')
        ->assertSeeInOrder(['Zainab Idle', 'Peter Otieno']);
});
