<?php

use App\Livewire\AlertsBell;
use App\Models\Department;
use App\Models\User;
use App\Notifications\ActivityAlert;
use App\Services\Alerts;
use Livewire\Livewire;

it('toasts an alert that arrives while the page is open, once', function () {
    $hod = headOfDepartment()->fresh();
    $bell = Livewire::actingAs($hod)->test(AlertsBell::class);

    $bell->call('checkForNew')->assertNotDispatched('notify');

    $this->travel(5)->seconds();
    app(Alerts::class)->toUsers([$hod], ActivityAlert::accessChanged(User::ROLE_HOD, Department::query()->where('name', 'ICT')->first()));

    $bell->call('checkForNew')->assertDispatched('notify', type: 'info');
    $bell->call('checkForNew')->assertNotDispatched('notify');
});
