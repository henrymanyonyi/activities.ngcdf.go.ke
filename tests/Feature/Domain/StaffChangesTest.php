<?php

use App\Exceptions\ActivityWorkflowException;
use App\Livewire\StaffList;
use App\Models\Department;
use App\Models\Designation;
use App\Models\ScheduledStaffChange;
use App\Models\Staff;
use App\Models\StaffChange;
use App\Models\User;
use App\Notifications\AccountAccessGranted;
use App\Services\StaffChanges;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    // The examples' effective date is today unless a test moves it.
    $this->travelTo(now()->setDate(2026, 11, 1)->setTime(9, 0));
    $this->changes = app(StaffChanges::class);
    $this->ict = Department::query()->where('name', 'ICT')->firstOrFail();
    $this->audit = Department::query()->where('name', 'Internal Audit')->firstOrFail();
    $this->officer = Staff::factory()->create(['name' => 'Asha Promoted', 'email' => 'asha.promoted@ngcdf.go.ke', 'phone' => '+254712111222', 'department_id' => $this->audit->id]);
});

/** @param  array<string, mixed>  $overrides */
function positionChange(array $overrides = []): array
{
    return $overrides + ['department_id' => null, 'access' => StaffChanges::KEEP, 'effective_on' => '2026-11-01', 'reason' => 'Board resolution 14/2026', 'reference' => 'HR/PROM/14/2026'];
}

it('promotes an officer to Head of a new department, making their account from the staff list and recording the change', function () {
    $director = Designation::query()->where('name', 'Director')->firstOrFail();

    $result = $this->changes->apply($this->officer, positionChange(['department_id' => $this->ict->id, 'designation_id' => $director->id, 'access' => User::ROLE_HOD]), assistant());

    $account = User::query()->where('staff_id', $this->officer->id)->sole();
    expect($result['password'])->not->toBeNull()
        ->and($account)->email->toBe('asha.promoted@ngcdf.go.ke')->phone->toBe('+254712111222')->department_id->toBe($this->ict->id)->is_active->toBeTrue()
        ->and($account->hasRole(User::ROLE_HOD))->toBeTrue()
        ->and($account->can('memos.originate'))->toBeTrue()->and($account->can('activities.view'))->toBeFalse()->and($account->can('users.manage'))->toBeFalse()
        ->and($this->officer->fresh())->department_id->toBe($this->ict->id)->designation_id->toBe($director->id)
        ->and(StaffChange::query()->sole())
        ->from_department_id->toBe($this->audit->id)->to_department_id->toBe($this->ict->id)
        ->from_role->toBeNull()->to_role->toBe(User::ROLE_HOD)
        ->effective_on->toDateString()->toBe('2026-11-01')->reference->toBe('HR/PROM/14/2026');
});

it('keeps one active Head per department, replacing the current Head only when asked', function () {
    $current = headOfDepartment($this->ict);
    DB::table('sessions')->insert(['id' => 'old-head', 'user_id' => $current->id, 'payload' => '', 'last_activity' => time()]);
    $cos = chiefOfStaff();

    expect(fn () => $this->changes->apply($this->officer, positionChange(['department_id' => $this->ict->id, 'access' => User::ROLE_HOD]), $cos))
        ->toThrow(ActivityWorkflowException::class, 'is the active Head of ICT');

    $this->changes->apply($this->officer, positionChange(['department_id' => $this->ict->id, 'access' => User::ROLE_HOD, 'replace_head' => true]), $cos);

    expect($current->fresh()->is_active)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $current->id)->exists())->toBeFalse()
        ->and(User::query()->role(User::ROLE_HOD)->where('is_active', true)->where('department_id', $this->ict->id)->pluck('staff_id')->all())->toBe([$this->officer->id]);
});

it('ends a Head\'s access to the department they leave, never letting them keep it by accident', function () {
    $hod = headOfDepartment($this->audit);
    $hod->update(['staff_id' => $this->officer->id]);
    $admin = User::factory()->admin()->create();

    expect(fn () => $this->changes->apply($this->officer, positionChange(['department_id' => $this->ict->id]), $admin))
        ->toThrow(ActivityWorkflowException::class, 'Moving them to another department ends that');

    $this->changes->apply($this->officer, positionChange(['department_id' => $this->ict->id, 'access' => StaffChanges::NONE]), $admin);
    expect($hod->fresh())->is_active->toBeFalse()->department_id->toBe($this->audit->id)
        ->and(StaffChange::query()->sole())->from_role->toBe(User::ROLE_HOD)->to_role->toBeNull();

    // Made Head of the new department later: the same account, now scoped to ICT only.
    $this->changes->apply($this->officer, positionChange(['department_id' => $this->ict->id, 'access' => User::ROLE_HOD]), $admin);
    expect($hod->fresh())->is_active->toBeTrue()->department_id->toBe($this->ict->id)
        ->and(StaffChange::query()->count())->toBe(2);
});

it('lets only authorised officers change positions, and only users.manage holders give office roles', function () {
    expect(fn () => $this->changes->apply($this->officer, positionChange(['department_id' => $this->ict->id]), headOfDepartment($this->ict)))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->changes->apply($this->officer, positionChange(['department_id' => $this->ict->id, 'access' => User::ROLE_CHIEF_OF_STAFF]), assistant()))->toThrow(AuthorizationException::class, 'office role')
        ->and(fn () => $this->changes->apply($this->officer, positionChange(['department_id' => $this->ict->id, 'reason' => ' ']), assistant()))->toThrow(ActivityWorkflowException::class, 'reason');

    $cos = chiefOfStaff();
    expect(fn () => $this->changes->apply($this->officer, positionChange(['department_id' => $this->audit->id, 'access' => User::ROLE_CHIEF_OF_STAFF]), $cos))->toThrow(ActivityWorkflowException::class, 'is the active Chief of Staff');

    // Nobody changes their own access.
    $cosStaff = Staff::factory()->create(['email' => $cos->email]);
    expect(fn () => $this->changes->apply($cosStaff, positionChange(['department_id' => $this->ict->id, 'access' => StaffChanges::NONE]), $cos))->toThrow(AuthorizationException::class, 'your own');
    expect($cos->fresh()->is_active)->toBeTrue()->and(StaffChange::query()->count())->toBe(0);
});

it('records a change from the staff list screen and shows the one-time password once', function () {
    Livewire::actingAs(assistant())->test(StaffList::class)
        ->assertSee('Change Asha Promoted\'s position or access', false)
        ->call('openPosition', $this->officer->id)
        ->assertSee('No account')
        ->set('posDepartment', (string) $this->ict->id)->set('posAccess', User::ROLE_HOD)
        ->call('savePosition')->assertHasErrors('posReason')
        ->set('posReason', 'Promoted by the Board')
        ->call('savePosition')->assertHasNoErrors()
        ->assertSee('One-time password for asha.promoted@ngcdf.go.ke');

    Livewire::actingAs(headOfDepartment($this->audit))->test(StaffList::class)->assertDontSee('position or access');
    Livewire::actingAs(headOfDepartment($this->ict))->test(StaffList::class)->call('openPosition', $this->officer->id)->assertForbidden();
});

it('emails a newly promoted Head a link to set their own password, with which they sign in and reach their memos', function () {
    Notification::fake();

    $result = $this->changes->apply($this->officer, positionChange(['department_id' => $this->ict->id, 'access' => User::ROLE_HOD]), assistant());
    $account = $result['account'];

    $token = null;
    Notification::assertSentTo($account, AccountAccessGranted::class, function (AccountAccessGranted $notification) use (&$token) {
        $token = $notification->token;

        return $notification->access === 'Head of Department';
    });

    $this->post('/reset-password', ['token' => $token, 'email' => 'asha.promoted@ngcdf.go.ke', 'password' => 'Head-of-ICT-2026!', 'password_confirmation' => 'Head-of-ICT-2026!'])
        ->assertSessionHasNoErrors();
    $this->post('/login', ['email' => 'asha.promoted@ngcdf.go.ke', 'password' => 'Head-of-ICT-2026!'])->assertRedirect('/home');
    $this->post(route('privacy.consent.accept'), ['agree' => '1']);

    $this->get('/home')->assertRedirect(route('memos.index'));
    $this->get(route('memos.index'))->assertOk();
    $this->get(route('dashboard'))->assertForbidden();
});

it('sends no password link to a Head who already had active access', function () {
    $hod = headOfDepartment($this->audit);
    $hod->update(['staff_id' => $this->officer->id]);
    Notification::fake();

    $this->changes->apply($this->officer, positionChange(['department_id' => $this->ict->id, 'access' => User::ROLE_HOD]), assistant());

    Notification::assertNothingSent();
});

it('ends the officer\'s place as Manager of the department they leave', function () {
    $this->audit->update(['manager_staff_id' => $this->officer->id]);

    $this->changes->apply($this->officer, positionChange(['department_id' => $this->ict->id]), assistant());

    expect($this->audit->fresh()->manager_staff_id)->toBeNull();
});

it('says so when the department has no Head to replace, and names the account the officer will get', function () {
    Livewire::actingAs(assistant())->test(StaffList::class)
        ->call('openPosition', $this->officer->id)
        ->set('posDepartment', (string) $this->audit->id)->set('posAccess', User::ROLE_HOD)
        ->assertSee('Internal Audit has no active Head of Department now')
        ->assertSee('An account is made with')
        ->assertDontSee('Replace the current Head');
});

it('schedules a change effective on a later date and applies it on that date', function () {
    $result = $this->changes->apply($this->officer, positionChange(['department_id' => $this->ict->id, 'access' => User::ROLE_HOD, 'effective_on' => '2026-11-10']), assistant());

    expect($result['scheduled'])->status->toBe(ScheduledStaffChange::SCHEDULED)
        ->and(User::query()->where('staff_id', $this->officer->id)->exists())->toBeFalse()
        ->and($this->officer->fresh()->department_id)->toBe($this->audit->id);

    $this->travelTo(now()->setDate(2026, 11, 9));
    expect($this->changes->applyDue())->toBe(['applied' => 0, 'failed' => 0]);

    $this->travelTo(now()->setDate(2026, 11, 10)->setTime(0, 15));
    expect($this->changes->applyDue())->toBe(['applied' => 1, 'failed' => 0])
        ->and(User::query()->where('staff_id', $this->officer->id)->sole())->department_id->toBe($this->ict->id)->is_active->toBeTrue()
        ->and($result['scheduled']->fresh())->status->toBe(ScheduledStaffChange::APPLIED)->staff_change_id->toBe(StaffChange::query()->sole()->id)
        ->and(StaffChange::query()->sole()->effective_on->toDateString())->toBe('2026-11-10');
});

it('hands a department over from its Head to a successor at once', function () {
    $head = headOfDepartment($this->ict);
    $headStaff = Staff::factory()->create(['department_id' => $this->ict->id]);
    $head->update(['staff_id' => $headStaff->id]);
    $successor = Staff::factory()->create(['name' => 'Next Head', 'email' => 'next.head@ngcdf.go.ke', 'department_id' => $this->ict->id]);

    $this->changes->handOver($headStaff, positionChange(['department_id' => $this->ict->id, 'access' => StaffChanges::NONE]), $successor, assistant());

    expect($head->fresh()->is_active)->toBeFalse()
        ->and(User::query()->role(User::ROLE_HOD)->where('is_active', true)->where('department_id', $this->ict->id)->sole()->staff_id)->toBe($successor->id)
        ->and(StaffChange::query()->where('staff_id', $successor->id)->sole()->reason)->toStartWith('Succeeds');
});

it('schedules a handover for a later date, ending the old Head before appointing the new one', function () {
    $head = headOfDepartment($this->ict);
    $headStaff = Staff::factory()->create(['department_id' => $this->ict->id]);
    $head->update(['staff_id' => $headStaff->id]);
    $successor = Staff::factory()->create(['email' => 'next.head@ngcdf.go.ke', 'department_id' => $this->ict->id]);

    $this->changes->handOver($headStaff, positionChange(['department_id' => $this->ict->id, 'access' => StaffChanges::NONE, 'effective_on' => '2026-12-01']), $successor, assistant());
    expect(ScheduledStaffChange::query()->pending()->count())->toBe(2)->and($head->fresh()->is_active)->toBeTrue();

    $this->travelTo(now()->setDate(2026, 12, 1));
    expect($this->changes->applyDue())->toBe(['applied' => 2, 'failed' => 0])
        ->and($head->fresh()->is_active)->toBeFalse()
        ->and(User::query()->role(User::ROLE_HOD)->where('is_active', true)->where('department_id', $this->ict->id)->sole()->staff_id)->toBe($successor->id);
});

it('refuses to schedule a second Head unless the current one is replaced or leaves by then, and lets a scheduled change be cancelled', function () {
    headOfDepartment($this->ict);
    $assistant = assistant();

    expect(fn () => $this->changes->apply($this->officer, positionChange(['department_id' => $this->ict->id, 'access' => User::ROLE_HOD, 'effective_on' => '2026-11-10']), $assistant))
        ->toThrow(ActivityWorkflowException::class, 'is the active Head of ICT');

    $scheduled = $this->changes->apply($this->officer, positionChange(['department_id' => $this->ict->id, 'access' => User::ROLE_HOD, 'replace_head' => true, 'effective_on' => '2026-11-10']), $assistant)['scheduled'];
    expect(fn () => $this->changes->apply($this->officer, positionChange(['department_id' => $this->ict->id]), $assistant))->toThrow(ActivityWorkflowException::class, 'already has a change scheduled');

    $this->changes->cancel($scheduled, $assistant);
    $this->travelTo(now()->setDate(2026, 11, 10));

    expect($this->changes->applyDue())->toBe(['applied' => 0, 'failed' => 0])
        ->and($scheduled->fresh())->status->toBe(ScheduledStaffChange::CANCELLED)->cancelled_by->toBe($assistant->id);
});

it('marks a scheduled change failed, and alerts who scheduled it, when it can no longer apply', function () {
    $assistant = assistant();
    $scheduled = $this->changes->apply($this->officer, positionChange(['department_id' => $this->ict->id, 'access' => User::ROLE_HOD, 'effective_on' => '2026-11-10']), $assistant)['scheduled'];
    headOfDepartment($this->ict);

    $this->travelTo(now()->setDate(2026, 11, 10));

    expect($this->changes->applyDue())->toBe(['applied' => 0, 'failed' => 1])
        ->and($scheduled->fresh())->status->toBe(ScheduledStaffChange::FAILED)->outcome->toContain('is the active Head of ICT')
        ->and($assistant->fresh()->unreadNotifications()->sole()->data['kind'])->toBe('staff_change_failed');
});

it('offers a successor when ending a Head\'s role from the staff list, and schedules a later handover', function () {
    $head = headOfDepartment($this->ict);
    $headStaff = Staff::factory()->create(['name' => 'Old Head', 'department_id' => $this->ict->id]);
    $head->update(['staff_id' => $headStaff->id]);
    $successor = Staff::factory()->create(['name' => 'Next Head', 'email' => 'next.head@ngcdf.go.ke', 'department_id' => $this->ict->id]);

    Livewire::actingAs(assistant())->test(StaffList::class)
        ->call('openPosition', $headStaff->id)
        ->assertDontSee('Successor as Head of ICT')
        ->set('posAccess', StaffChanges::NONE)
        ->assertSee('Successor as Head of ICT')->assertSee('Next Head')
        ->set('posSuccessor', (string) $successor->id)
        ->set('posEffective', '2026-11-15')->set('posReason', 'Retirement')
        ->call('savePosition')->assertHasNoErrors()
        ->call('openPosition', $headStaff->id)
        ->assertSee('Scheduled')->assertSee('Cancel');

    expect(ScheduledStaffChange::query()->pending()->pluck('staff_id')->sort()->values()->all())->toBe(collect([$headStaff->id, $successor->id])->sort()->values()->all())
        ->and($head->fresh()->is_active)->toBeTrue();
});
