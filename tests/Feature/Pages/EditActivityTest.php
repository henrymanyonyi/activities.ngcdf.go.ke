<?php

use App\Enums\DecisionType;
use App\Exceptions\ActivityWorkflowException;
use App\Livewire\Activities\Form;
use App\Livewire\Activities\Show;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ActivityEditor;
use App\Services\ActivityLifecycle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => Storage::fake('local'));

function approvedOnBehalf($activity, $by)
{
    app(ActivityLifecycle::class)->recordDecision($activity, DecisionType::Approved, null, 'M/1', today(), UploadedFile::fake()->create('d.pdf'), $by);

    return $activity->fresh();
}

it('opens and saves the edit form for a draft with no notes, outputs or memo number', function () {
    $cos = chiefOfStaff();
    $activity = capturedActivity($cos);
    $team = $activity->participants()->pluck('id')->all();

    expect($activity->notes)->toBeNull()->and($activity->expected_outputs)->toBeNull()->and($activity->source_reference)->toBeNull();

    $this->actingAs($cos)->get(route('activities.edit', $activity))->assertOk()->assertSee($activity->title);

    Livewire::actingAs($cos)->test(Form::class, ['activity' => $activity])
        ->assertSet('notes', '')
        ->set('title', 'Proposal review, Western Region (revised)')
        ->set('notes', 'Venue moved to Kakamega town')
        ->set('days', 4)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('activities.show', $activity));

    $activity->refresh();
    expect($activity->title)->toBe('Proposal review, Western Region (revised)')
        ->and($activity->notes)->toBe('Venue moved to Kakamega town')
        ->and($activity->days)->toBe(4)
        ->and($activity->end_date->toDateString())->toBe($activity->start_date->copy()->addDays(3)->toDateString())
        ->and($activity->participants()->pluck('id')->all())->toBe($team)
        ->and(AuditLog::query()->where('auditable_type', $activity->getMorphClass())->where('auditable_id', $activity->id)->where('event', 'updated')->get()
            ->contains(fn (AuditLog $log) => ($log->old_values['title'] ?? null) === 'Proposal review, Western Region'
                && ($log->new_values['title'] ?? null) === 'Proposal review, Western Region (revised)'))->toBeTrue();
});

it('keeps the entered values and explains a validation error', function () {
    $cos = chiefOfStaff();
    $activity = capturedActivity($cos);

    Livewire::actingAs($cos)->test(Form::class, ['activity' => $activity])
        ->set('title', '')
        ->set('notes', 'Kept while the title is fixed')
        ->call('save')
        ->assertHasErrors(['title' => 'required'])
        ->assertSet('notes', 'Kept while the title is fixed');

    expect($activity->fresh()->title)->toBe('Proposal review, Western Region');
});

it('lets an approved activity be corrected but not re-dated through the form', function () {
    $cos = chiefOfStaff();
    $activity = approvedOnBehalf(capturedActivity($cos), $cos);

    Livewire::actingAs($cos)->test(Show::class, ['activity' => $activity])->assertSee(route('activities.edit', $activity));
    $this->actingAs($cos)->get(route('activities.index'))->assertSee(route('activities.edit', $activity));

    Livewire::actingAs($cos)->test(Form::class, ['activity' => $activity])
        ->set('title', 'Corrected title')
        ->call('save')
        ->assertHasNoErrors();

    expect($activity->fresh()->title)->toBe('Corrected title');

    // A crafted request cannot move the dates around Postpone and Extend.
    Livewire::actingAs($cos)->test(Form::class, ['activity' => $activity->fresh()])
        ->set('days', 9)
        ->call('save')
        ->assertDispatched('notify', type: 'error');

    expect($activity->fresh()->days)->toBe(3);
});

it('refuses to edit a read-only activity, on the page and in the service', function () {
    $cos = chiefOfStaff();
    $activity = approvedOnBehalf(capturedActivity($cos), $cos);
    app(ActivityLifecycle::class)->cancel($activity, 'No longer needed', $cos);

    $this->actingAs($cos)->get(route('activities.edit', $activity))->assertForbidden();

    expect(fn () => app(ActivityEditor::class)->update($activity->fresh(), ['title' => 'x', 'start_date' => $activity->start_date, 'days' => 3], $cos))
        ->toThrow(ActivityWorkflowException::class);
});

it('checks the permission in the service, not only on the route', function () {
    $activity = capturedActivity(chiefOfStaff());
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->get(route('activities.edit', $activity))->assertForbidden();

    expect(fn () => app(ActivityEditor::class)->update($activity, ['title' => 'x'], $outsider))
        ->toThrow(AuthorizationException::class);
});
