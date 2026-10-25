<?php

use App\Livewire\Users;
use App\Models\Department;
use App\Models\Staff;
use App\Models\User;
use Livewire\Livewire;

it('creates a Head of Department account from the staff list, picking up their email, mobile and department', function () {
    $supplyChain = Department::query()->where('name', 'Supply Chain')->firstOrFail();
    $officer = Staff::factory()->create(['name' => 'Grace Example', 'email' => 'grace.example@ngcdf.go.ke', 'phone' => '+254733444555', 'department_id' => $supplyChain->id]);

    Livewire::actingAs(chiefOfStaff())->test(Users::class)
        ->call('openCreate', 'hod')
        ->set('staffSearch', 'Grace')
        ->assertSee('Grace Example')
        ->call('pickStaff', $officer->id)
        ->assertSet('email', 'grace.example@ngcdf.go.ke')
        ->assertSet('phone', '+254733444555')
        ->assertSet('department_id', (string) $supplyChain->id)
        ->call('create')
        ->assertHasNoErrors();

    expect(User::query()->where('email', 'grace.example@ngcdf.go.ke')->sole())
        ->staff_id->toBe($officer->id)
        ->department_id->toBe($supplyChain->id)
        ->mobileNumber()->toBe('+254733444555');
});

it('allows one account per officer and only a Kenyan mobile number', function () {
    $officer = Staff::factory()->create();
    headOfDepartment()->update(['staff_id' => $officer->id]);

    Livewire::actingAs(chiefOfStaff())->test(Users::class)
        ->call('openCreate', 'assistant_chief_of_staff')
        ->set('name', 'Someone Else')
        ->set('email', 'someone@ngcdf.go.ke')
        ->set('staff_id', $officer->id)
        ->set('phone', '020 123 4567')
        ->call('create')
        ->assertHasErrors(['staff_id' => 'unique', 'phone']);
});

it('sets the mobile number of an existing account from its contact popup', function () {
    $hod = headOfDepartment();

    Livewire::actingAs(chiefOfStaff())->test(Users::class)
        ->call('openContact', $hod->id)
        ->set('phone', '0711 222 333')
        ->call('saveContact')
        ->assertHasNoErrors();

    expect($hod->fresh()->phone)->toBe('+254711222333');
});
