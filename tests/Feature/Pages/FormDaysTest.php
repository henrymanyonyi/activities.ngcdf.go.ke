<?php

use App\Livewire\Activities\Form;
use App\Livewire\Activities\Show;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('survives clearing the number of days and explains the error', function ($value) {
    Livewire::actingAs(chiefOfStaff())->test(Form::class)
        ->set('start_date', today()->toDateString())
        ->set('days', $value)
        ->assertOk()
        ->call('save')
        ->assertHasErrors('days');
})->with(['blank' => '', 'null' => null, 'zero' => 0]);

it('survives clearing the postpone and extension day fields', function () {
    Storage::fake('local');
    $activity = capturedActivity(chiefOfStaff());

    Livewire::actingAs(chiefOfStaff())->test(Show::class, ['activity' => $activity])
        ->set('newDays', '')
        ->set('extraDays', '')
        ->assertOk()
        ->set('showExtend', true)
        ->set('reason', 'Test')
        ->call('extend')
        ->assertHasErrors('extraDays');
});
