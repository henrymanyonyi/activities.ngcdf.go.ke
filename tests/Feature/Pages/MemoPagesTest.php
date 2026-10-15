<?php

use App\Enums\ActivityStatus;
use App\Enums\ReviewOffice;
use App\Livewire\Activities\Form;
use App\Livewire\Activities\Show;
use App\Livewire\Memos\Board;
use App\Livewire\Memos\Show as MemoShow;
use App\Livewire\Users;
use App\Models\AccessLog;
use App\Models\Activity;
use App\Models\ActivityCategory;
use App\Models\Department;
use App\Models\User;
use App\Services\ActivityLifecycle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->ict = Department::query()->where('name', 'ICT')->firstOrFail();
    $this->other = Department::query()->where('name', '!=', 'ICT')->firstOrFail();
    $this->hod = headOfDepartment($this->ict);
});

it('lands a Head of Department on their memos and keeps them out of every Office of the CEO page', function () {
    $this->actingAs($this->hod)->get(route('home'))->assertRedirect(route('memos.index'));
    $this->actingAs($this->hod)->get(route('memos.index'))->assertOk()->assertSee('Create new activity memo')->assertSee('Restricted');

    $activity = capturedActivity(chiefOfStaff());
    foreach (['dashboard', 'decisions.index', 'activities.index', 'activities.create', 'calendar', 'reports.index', 'users.index', 'settings.reference'] as $route) {
        $this->actingAs($this->hod)->get(route($route))->assertForbidden();
    }
    $this->actingAs($this->hod)->get(route('activities.show', $activity))->assertForbidden();
    $this->actingAs($this->hod)->get(route('reports.show', 'memo-workflow'))->assertNotFound();
});

it('shows a Head of Department only their own department\'s memos and files', function () {
    $own = departmentMemo($this->hod, ['title' => 'Own department memo']);
    $foreign = departmentMemo(headOfDepartment($this->other), ['title' => 'Other department memo']);

    $this->actingAs($this->hod)->get(route('memos.index', ['tab' => 'all']))->assertOk()->assertSee('Own department memo')->assertDontSee('Other department memo');

    $this->actingAs($this->hod)->get(route('memos.show', $own))->assertOk()->assertSee('Own department memo');
    $this->actingAs($this->hod)->get(route('memos.show', $foreign))->assertForbidden();
    $this->actingAs($this->hod)->get(route('memos.edit', $foreign))->assertForbidden();

    $this->actingAs($this->hod)->get(route('documents.show', $own->documents()->first()))->assertOk();
    $this->actingAs($this->hod)->get(route('documents.show', $foreign->documents()->first()))->assertForbidden();

    expect(AccessLog::query()->where('user_id', $this->hod->id)->where('event', 'view')->where('subject_id', $own->id)->exists())->toBeTrue();
});

it('creates a memo through the form for the Head of Department\'s own department only', function () {
    Livewire::actingAs($this->hod)->test(Form::class)
        ->assertSet('organising_department_id', (string) $this->ict->id)
        ->set('title', 'ICT Department Quarterly Systems Review and Staff Training')
        ->set('purpose', 'Review departmental systems and train staff.')
        ->set('target_audience', 'ICT staff and invited departmental representatives')
        ->set('requested_budget', '250000')
        ->set('activity_category_id', (string) ActivityCategory::query()->value('id'))
        ->set('organising_department_id', (string) $this->other->id)
        ->set('start_date', today()->addDays(20)->toDateString())
        ->set('venue', 'NGCDF Board headquarters')
        ->set('memo', UploadedFile::fake()->create('memo.pdf', 50, 'application/pdf'))
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('memos.show', Activity::sole()));

    expect(Activity::sole())
        ->organising_department_id->toBe($this->ict->id)
        ->responsible_hod_id->toBe($this->hod->id)
        ->requested_budget->toBe('250000.00')
        ->target_audience->toBe('ICT staff and invited departmental representatives');
});

it('rejects a budget that is not an amount', function () {
    Livewire::actingAs($this->hod)->test(Form::class)
        ->set('title', 'x')->set('purpose', 'x')
        ->set('activity_category_id', (string) ActivityCategory::query()->value('id'))
        ->set('start_date', today()->addDays(20)->toDateString())
        ->set('requested_budget', '12,5x')
        ->call('save')
        ->assertHasErrors(['requested_budget']);
});

it('refuses to save over someone else\'s later change', function () {
    $memo = departmentMemo($this->hod);
    $form = Livewire::actingAs($this->hod)->test(Form::class, ['activity' => $memo]);

    $this->travel(1)->minutes();
    $memo->fresh()->update(['title' => 'Changed elsewhere']);

    $form->set('title', 'My change')->call('save')->assertNoRedirect();
    expect($memo->fresh()->title)->toBe('Changed elsewhere');
});

it('submits from the memo page, after which the Head of Department can no longer edit it', function () {
    $memo = departmentMemo($this->hod);

    Livewire::actingAs($this->hod)->test(MemoShow::class, ['activity' => $memo])
        ->call('openSubmit')
        ->set('office', ReviewOffice::ChiefOfStaff->value)
        ->call('submit')
        ->assertHasNoErrors();

    expect($memo->fresh())->status->toBe(ActivityStatus::UnderReview)->review_office->toBe(ReviewOffice::ChiefOfStaff);
    $this->actingAs($this->hod)->get(route('memos.edit', $memo))->assertForbidden();
    $this->actingAs($this->hod)->get(route('memos.show', $memo))->assertOk()->assertDontSee('Submit for review</span>', false);
});

it('lets the reviewer return a memo from the activity page only with a reason', function () {
    $memo = departmentMemo($this->hod);
    app(ActivityLifecycle::class)->submitForReview($memo, $this->hod);
    $acos = assistant();

    $page = Livewire::actingAs($acos)->test(Show::class, ['activity' => $memo->fresh()])
        ->assertSee('Return for correction')
        ->assertSee('Forward to CEO');

    $page->set('showReturnHod', true)->call('returnToHod')->assertHasErrors(['reviewText' => 'Say what the Head of Department must correct.']);
    expect($memo->fresh()->status)->toBe(ActivityStatus::UnderReview);

    $page->set('reviewText', 'Add the missing justification.')->call('returnToHod')->assertHasNoErrors();
    expect($memo->fresh())->status->toBe(ActivityStatus::ReturnedToHod)->status_reason->toBe('Add the missing justification.');

    $this->actingAs($this->hod)->get(route('memos.show', $memo))->assertSee('What to correct')->assertSee('Add the missing justification.')->assertSee('Resubmit for review');
});

it('shows each role the board tabs it acts on, with the waiting count first', function () {
    $memo = departmentMemo($this->hod);
    app(ActivityLifecycle::class)->submitForReview($memo, $this->hod);

    Livewire::actingAs(assistant())->test(Board::class)->assertSee('Awaiting my review')->assertSee($memo->title)->assertSee('Assistant Chief of Staff');
    Livewire::actingAs(chiefOfStaff())->test(Board::class)->assertSee('Awaiting my review')->assertDontSee($memo->title)
        ->call('setTab', 'in_review')->assertSee($memo->title);
    Livewire::actingAs(ceo())->test(Board::class)->assertSee('Awaiting my approval')->assertSee('Recent decisions');
    Livewire::actingAs($this->hod)->test(Board::class)->call('setTab', 'pending')->assertSee($memo->title)->assertSee('Review the memo, then forward it or return it for correction.');
});

it('escapes memo text on the board', function () {
    departmentMemo($this->hod, ['title' => '<script>alert(1)</script> review']);

    $this->actingAs($this->hod)->get(route('memos.index'))->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;', false);
});

it('opens the memo report for the Office of the CEO', function () {
    departmentMemo($this->hod, ['title' => 'Quarterly systems review']);

    $this->actingAs(chiefOfStaff())->get(route('reports.show', 'memo-workflow'))->assertOk()->assertSee('Quarterly systems review')->assertSee($this->hod->name);
});

it('creates one active Head of Department per department, linked to it', function () {
    $cos = chiefOfStaff();

    Livewire::actingAs($cos)->test(Users::class)
        ->call('openCreate', User::ROLE_HOD)
        ->set('name', 'Second ICT Head')->set('email', 'ict2@ngcdf.go.ke')->set('department_id', (string) $this->ict->id)
        ->call('create')
        ->assertHasErrors(['role']);

    Livewire::actingAs($cos)->test(Users::class)
        ->call('openCreate', User::ROLE_HOD)
        ->set('name', 'Head of the other department')->set('email', 'other@ngcdf.go.ke')
        ->call('create')->assertHasErrors(['department_id'])
        ->set('department_id', (string) $this->other->id)
        ->call('create')->assertHasNoErrors();

    expect(User::query()->where('email', 'other@ngcdf.go.ke')->sole())
        ->department_id->toBe($this->other->id)
        ->and(User::query()->where('email', 'other@ngcdf.go.ke')->sole()->isHeadOfDepartment())->toBeTrue();
});

it('creates a Head of Department account from the command line with its department', function () {
    $this->artisan('activities:create-user', ['role' => 'hod', 'email' => 'hod.other@ngcdf.go.ke', 'name' => 'Head', '--department' => $this->other->name])->assertSuccessful();
    $this->artisan('activities:create-user', ['role' => 'hod', 'email' => 'hod.x@ngcdf.go.ke', 'name' => 'Head'])->assertFailed();

    expect(User::query()->where('email', 'hod.other@ngcdf.go.ke')->sole()->department_id)->toBe($this->other->id);
});
