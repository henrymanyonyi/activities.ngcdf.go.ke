<?php

use App\Enums\DecisionType;
use App\Livewire\Activities\Show;
use App\Models\Department;
use App\Models\Staff;
use App\Services\ActivityLifecycle;
use App\Services\ParticipantChecks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->ict = Department::factory()->create(['name' => 'ICT Dept']);
    $this->audit = Department::factory()->create(['name' => 'Audit Dept']);
    Staff::factory()->count(3)->create(['department_id' => $this->ict->id]);
    Staff::factory()->count(4)->create(['department_id' => $this->audit->id]);
    $this->cos = chiefOfStaff();
    $this->activity = capturedActivity($this->cos, [], []);
});

function picker($test)
{
    return Livewire::actingAs($test->cos)->test(Show::class, ['activity' => $test->activity])->set('showAddStaff', true);
}

it('selects everyone shown, or everyone in one department', function () {
    $c = picker($this)->call('selectShown');
    expect($c->get('selectedStaff'))->toHaveCount(7);

    $c->call('clearShown');
    expect($c->get('selectedStaff'))->toBeEmpty();

    $c->call('toggleDepartment', (string) $this->ict->id)
        ->assertSee('3 of 3 selected');
    expect($c->get('selectedStaff'))->toHaveCount(3);

    $c->call('toggleDepartment', (string) $this->ict->id);
    expect($c->get('selectedStaff'))->toBeEmpty();
});

it('keeps picks while the filters change, and can show only the selection', function () {
    $ict = Staff::where('department_id', $this->ict->id)->pluck('id');
    $audit = Staff::where('department_id', $this->audit->id)->pluck('id');

    $c = picker($this)
        ->set('staffDepartment', (string) $this->ict->id)
        ->set('selectedStaff', [$ict[0]])
        ->set('staffDepartment', (string) $this->audit->id)
        ->call('selectShown');

    expect($c->get('selectedStaff'))->toHaveCount(5)->toContain($ict[0])->toContain($audit[3]);

    $c->set('staffDepartment', '')->set('showSelectedOnly', true)->assertSee('5 shown')
        ->call('unselect', $ict[0])->assertSee('4 shown');
});

it('adds the whole selection, with one reason for everyone who overlaps', function () {
    $clash = Staff::where('department_id', $this->audit->id)->get();
    $other = capturedActivity($this->cos, ['start_date' => $this->activity->start_date->toDateString()], $clash->take(2)->all());
    app(ActivityLifecycle::class)->recordDecision($other, DecisionType::Approved, null, 'M/1', today(), UploadedFile::fake()->create('a.pdf'), $this->cos);

    expect(array_keys(app(ParticipantChecks::class)->conflictingStaff($this->activity->start_date, $this->activity->end_date, $this->activity->id)))
        ->toEqualCanonicalizing($clash->take(2)->pluck('id')->all());

    $c = picker($this)->call('selectShown')->assertSee('with an overlap')->call('addStaff');
    expect($this->activity->participants()->count())->toBe(0); // reasons missing

    $c->set('sameConflictReason', 'Both teams needed')->call('addStaff')->assertHasNoErrors();

    expect($this->activity->participants()->count())->toBe(7)
        ->and($this->activity->participants()->whereNotNull('conflict_reason')->count())->toBe(2);
});
