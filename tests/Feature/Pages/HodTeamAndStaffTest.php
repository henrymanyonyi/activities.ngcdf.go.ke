<?php

use App\Enums\ActivityStatus;
use App\Enums\DecisionType;
use App\Enums\ParticipantRole;
use App\Exceptions\ActivityWorkflowException;
use App\Livewire\Memos\Show as MemoShow;
use App\Livewire\Memos\Team;
use App\Livewire\StaffList;
use App\Models\Activity;
use App\Models\CostCategory;
use App\Models\County;
use App\Models\Department;
use App\Models\Staff;
use App\Models\User;
use App\Services\ActivityEditor;
use App\Services\ActivityLifecycle;
use App\Services\StaffImporter;
use App\Services\StaffRecords;
use Database\Seeders\SettingsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->ict = Department::query()->where('name', 'ICT')->firstOrFail();
    $this->hod = headOfDepartment($this->ict);
    $this->memo = departmentMemo($this->hod);
});

it('shows the Head of Department the staff list in their menu, with import but without activity counts', function () {
    Staff::factory()->create(['name' => 'Grace Wanjiru']);

    $this->actingAs($this->hod)->get(route('memos.index'))->assertSee(route('staff.index'));
    $this->actingAs($this->hod)->get(route('staff.index'))->assertOk()
        ->assertSee('Grace Wanjiru')
        ->assertSee('Add officer')
        ->assertSee('Import staff list')
        ->assertDontSee('Import HR list');
});

it('lets a Head of Department import a staff list: new officers become theirs, officers others added are left untouched, and nobody is deactivated', function () {
    $theirs = Staff::factory()->create(['staff_number' => 'PF-100', 'name' => 'Old Name', 'created_by' => $this->hod->id]);
    $others = Staff::factory()->create(['staff_number' => 'PF-200', 'name' => 'Office Record']);
    $csv = "PF Number,Name,Job Grade,Department,Active\nPF-100,Corrected Name,5,ICT,No\nPF-200,Changed By HOD,9,ICT,No\nPF-300,New Officer,6,ICT,No\n";

    Livewire::actingAs($this->hod)->test(StaffList::class)
        ->set('file', UploadedFile::fake()->createWithContent('staff.csv', $csv))
        ->call('import')
        ->assertHasNoErrors()
        ->assertDispatched('notify', message: 'Staff list imported: 1 added, 1 updated. 1 already on the list were left as they are.', type: 'success');

    expect($theirs->fresh())->name->toBe('Corrected Name')->is_active->toBeTrue()
        ->and($others->fresh())->name->toBe('Office Record')->job_grade->toBe('5')
        ->and(Staff::query()->where('staff_number', 'PF-300')->sole())
        ->created_by->toBe($this->hod->id)->source->toBe('department')->is_active->toBeTrue()->job_grade->toBe('6');

    // Once imported, the officer is theirs to correct.
    app(StaffRecords::class)->save(['staff_number' => 'PF-300', 'name' => 'New Officer Corrected', 'department_id' => $this->ict->id], $this->hod, Staff::query()->where('staff_number', 'PF-300')->sole());
    expect(Staff::query()->where('staff_number', 'PF-300')->value('name'))->toBe('New Officer Corrected');
});

it('keeps the staff import closed to roles without permission', function () {
    expect(fn () => app(StaffImporter::class)->import(base_path('docs/samples/hr-staff-list.csv'), User::factory()->admin()->create()->syncRoles([])))
        ->toThrow(AuthorizationException::class);
});

it('lets a Head of Department add a missing officer and correct only the officers they added', function () {
    Livewire::actingAs($this->hod)->test(StaffList::class)
        ->call('edit')
        ->set('staff_number', 'pf-9001')->set('name', 'Mary Achieng')
        ->set('form_department_id', (string) $this->ict->id)->set('job_grade', 'ngcdf 5')
        ->call('save')
        ->assertHasNoErrors();

    $added = Staff::query()->where('staff_number', 'PF-9001')->sole();
    expect($added)->created_by->toBe($this->hod->id)->source->toBe('department')->job_grade->toBe('5')->is_active->toBeTrue();

    Livewire::actingAs($this->hod)->test(StaffList::class)
        ->call('edit', $added->id)->set('name', 'Mary A. Achieng')->set('is_active', false)->call('save');

    expect($added->fresh())->name->toBe('Mary A. Achieng')->is_active->toBeTrue();

    $imported = Staff::factory()->create();
    Livewire::actingAs($this->hod)->test(StaffList::class)->call('edit', $imported->id)->assertForbidden();
    expect(fn () => app(StaffRecords::class)->save(['staff_number' => 'X1', 'name' => 'Changed', 'department_id' => $this->ict->id], $this->hod, $imported))
        ->toThrow(AuthorizationException::class);
});

it('keeps the staff list closed to roles without permission', function () {
    $this->actingAs(assistant())->get(route('staff.index'))->assertForbidden();
});

it('builds the team and costs on the Head of Department\'s draft, with DSA at the officer\'s standard rate', function () {
    $this->seed(SettingsSeeder::class);
    $nairobi = County::query()->where('name', 'Kakamega')->firstOrFail();
    app(ActivityEditor::class)->addLocation($this->memo, ['county_id' => $nairobi->id], $this->hod);
    $officer = Staff::factory()->create(['job_grade' => '5', 'department_id' => $this->ict->id]);
    app(ActivityEditor::class)->update($this->memo->fresh(), ['days' => 2], $this->hod); // one night away, so DSA applies

    $page = Livewire::actingAs($this->hod)->test(Team::class, ['activity' => $this->memo->fresh()])
        ->set('showAdd', true)
        ->set('selectedStaff', [$officer->id])
        ->call('addStaff')
        ->assertHasNoErrors();

    $page->set('externalName', 'County Commissioner\'s representative')->set('externalCategory', 'government_officer')->call('addExternal')->assertHasNoErrors();
    $page->call('openCost')
        ->set('costCategoryId', (string) CostCategory::query()->where('code', 'venue')->value('id') ?: (string) CostCategory::query()->value('id'))
        ->set('costDescription', 'Conference room')->set('costAmount', '45,000')
        ->call('saveCost')->assertHasNoErrors();

    $memo = $this->memo->fresh(['participants', 'costs']);
    $added = $memo->participants->firstWhere('staff_id', $officer->id);
    expect($memo->staff_count)->toBe(2) // the officer added here, and the one departmentMemo() starts with
        ->and($memo->external_count)->toBe(1)
        ->and($memo->costs->where('is_computed', true)->where('activity_participant_id', $added->id)->sole()->unit_rate)->toBe('14000.00')
        ->and($memo->costs->where('description', 'Conference room')->sole()->estimated_amount)->toBe('45000.00')
        ->and((float) $memo->estimated_total)->toBeGreaterThan(45000.0);

    // DSA comes only from the calculation, never typed in.
    $page->call('openCost')
        ->set('costCategoryId', (string) CostCategory::query()->where('code', 'dsa')->value('id'))
        ->set('costAmount', '1000')
        ->call('saveCost')
        ->assertHasErrors(['costCategoryId']);
});

it('lets the Head of Department add a missing officer from the team window, ticked and ready to add', function () {
    Livewire::actingAs($this->hod)->test(Team::class, ['activity' => $this->memo])
        ->set('showAdd', true)
        ->call('openStaffForm')
        ->assertSet('staffDepartmentId', (string) $this->ict->id)
        ->set('staffNumber', 'PF-7777')->set('staffName', 'John Kamau')->set('staffJobGrade', '6')
        ->call('saveStaff')
        ->assertHasNoErrors()
        ->assertSet('showAdd', true)
        ->assertSet('selectedStaff', [Staff::query()->where('staff_number', 'PF-7777')->value('id')])
        ->call('addStaff');

    expect($this->memo->fresh()->staff_count)->toBe(2);
});

it('refuses a duplicate PF number with a clear message', function () {
    Staff::factory()->create(['staff_number' => 'PF-1234']);

    Livewire::actingAs($this->hod)->test(Team::class, ['activity' => $this->memo])
        ->call('openStaffForm')
        ->set('staffNumber', 'PF-1234')->set('staffName', 'Someone')
        ->call('saveStaff')
        ->assertHasErrors(['staffNumber' => 'An officer with this PF number is already on the staff list. Search for them instead.']);
});

it('selects every available officer shown in one click, leaving out the unavailable, and clears them with a second', function () {
    $cos = chiefOfStaff();
    $first = Staff::factory()->create(['name' => 'Zawadi One', 'department_id' => $this->ict->id]);
    $second = Staff::factory()->create(['name' => 'Zawadi Two', 'department_id' => $this->ict->id]);
    $busy = Staff::factory()->create(['name' => 'Zawadi Busy', 'department_id' => $this->ict->id]);
    $elsewhere = Staff::factory()->create(['name' => 'Ticked Elsewhere']);
    $other = capturedActivity($cos, ['start_date' => $this->memo->start_date->copy()->subDay()->toDateString(), 'days' => 2], [$busy]);
    app(ActivityLifecycle::class)->recordDecision($other, DecisionType::Approved, null, 'M/9', today(), UploadedFile::fake()->create('d.pdf'), $cos);

    $page = Livewire::actingAs($this->hod)->test(Team::class, ['activity' => $this->memo])
        ->set('showAdd', true)
        ->set('selectedStaff', [$elsewhere->id])
        ->set('staffSearch', 'Zawadi')
        ->assertSee('Select all shown')
        ->assertSee('1 not available is left out')
        ->call('toggleShown');

    expect($page->get('selectedStaff'))->toEqualCanonicalizing([$elsewhere->id, $first->id, $second->id]);

    $page->assertSee('Clear all shown')->call('toggleShown');

    expect($page->get('selectedStaff'))->toBe([$elsewhere->id]);
});

it('shows an officer on another approved activity as unavailable, with the days but not the activity, and will not add them', function () {
    $cos = chiefOfStaff();
    $officer = Staff::factory()->create(['name' => 'Henry Otieno']);
    // Two days ending on the memo's day (SQLite compares these dates as text, so avoid a same-day-only span).
    $other = capturedActivity($cos, ['title' => 'Confidential other-department visit', 'start_date' => $this->memo->start_date->copy()->subDay()->toDateString(), 'days' => 2], [$officer]);
    app(ActivityLifecycle::class)->recordDecision($other, DecisionType::Approved, null, 'M/9', today(), UploadedFile::fake()->create('d.pdf'), $cos);
    $before = $this->memo->fresh()->staff_count;

    $page = Livewire::actingAs($this->hod)->test(Team::class, ['activity' => $this->memo])
        ->set('showAdd', true)
        ->set('staffSearch', 'Henry Otieno')
        ->assertSee('Not available until '.$this->memo->start_date->format('d M Y'))
        ->assertDontSee($other->reference)
        ->set('selectedStaff', [$officer->id])
        ->call('addStaff');

    // A Head of Department cannot override: only a release, or the Office of the CEO, can.
    expect($this->memo->fresh()->staff_count)->toBe($before);
    expect(fn () => app(ActivityEditor::class)->addStaff($this->memo, [$officer->id], ParticipantRole::Member, $this->hod, [$officer->id => 'Any reason']))
        ->toThrow(ActivityWorkflowException::class, 'Henry Otieno is not available: already on another approved activity from '.$other->start_date->format('d M Y').' to '.$other->end_date->format('d M Y').'. Choose someone else');

    $this->actingAs($this->hod)->get(route('memos.team', $this->memo))->assertOk()
        ->assertDontSee($other->reference)->assertDontSee('Confidential other-department visit');
});

it('locks the team once the memo is submitted, and closes it to other departments', function () {
    $officer = Staff::factory()->create();
    app(ActivityLifecycle::class)->submitForReview($this->memo, $this->hod);

    Livewire::actingAs($this->hod)->test(Team::class, ['activity' => $this->memo->fresh()])
        ->assertSee('can be changed again only if it is returned to you')
        ->assertDontSee('Add officers')
        ->set('selectedStaff', [$officer->id])
        ->call('addStaff');
    expect($this->memo->fresh()->staff_count)->toBe(1);

    $outsider = headOfDepartment(Department::query()->where('name', '!=', 'ICT')->firstOrFail());
    $this->actingAs($outsider)->get(route('memos.team', $this->memo))->assertForbidden();
});

it('adds a location with a county from the memo page', function () {
    $county = County::query()->where('name', 'Kisumu')->firstOrFail();

    Livewire::actingAs($this->hod)->test(MemoShow::class, ['activity' => $this->memo])
        ->set('showLocation', true)
        ->set('locCounty', (string) $county->id)->set('locVenue', 'Kisumu office')
        ->call('addLocation')
        ->assertHasNoErrors();

    expect($this->memo->locations()->where('county_id', $county->id)->exists())->toBeTrue();
});

it('needs no help from the Office of the CEO before forwarding: the reviewer forwards the Head of Department\'s team as it is', function () {
    Livewire::actingAs($this->hod)->test(Team::class, ['activity' => $this->memo])
        ->set('selectedStaff', [Staff::factory()->create()->id])->call('addStaff');
    app(ActivityLifecycle::class)->submitForReview($this->memo->fresh(), $this->hod);

    expect(app(ActivityLifecycle::class)->forward($this->memo->fresh(), assistant()))->toBe('the CEO')
        ->and(Activity::query()->find($this->memo->id)->status)->toBe(ActivityStatus::AwaitingDecision);
});
