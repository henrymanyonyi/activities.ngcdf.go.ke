<?php

use App\Enums\ActivityStatus;
use App\Enums\DecisionType;
use App\Http\Controllers\ParticipantTemplateController;
use App\Livewire\Activities\Form;
use App\Livewire\Activities\Register;
use App\Livewire\Activities\Show;
use App\Livewire\DecisionQueue;
use App\Livewire\Links;
use App\Livewire\Settings\Reference;
use App\Livewire\StaffList;
use App\Livewire\Submit;
use App\Livewire\Users;
use App\Models\Activity;
use App\Models\ActivityCategory;
use App\Models\County;
use App\Models\Department;
use App\Models\DsaRate;
use App\Models\Staff;
use App\Models\SubmissionLink;
use App\Models\User;
use App\Services\ActivityLifecycle;
use App\Services\SubmissionLinkService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => Storage::fake('local'));

it('records an activity through the form, with the first location and memo', function () {
    $cos = chiefOfStaff();
    $county = County::where('name', 'Nakuru')->firstOrFail();

    Livewire::actingAs($cos)->test(Form::class)
        ->set('title', 'Monitoring visit, Nakuru')
        ->set('purpose', 'Verify project status')
        ->set('activity_category_id', (string) ActivityCategory::query()->value('id'))
        ->set('organising_department_id', (string) Department::query()->value('id'))
        ->set('start_date', today()->addDays(20)->toDateString())
        ->set('days', 4)
        ->assertSee(today()->addDays(23)->format('d M Y'))
        ->set('county_id', (string) $county->id)
        ->set('memo', UploadedFile::fake()->create('memo.pdf', 50, 'application/pdf'))
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $activity = Activity::sole();
    expect($activity->nights)->toBe(3)
        ->and($activity->status)->toBe(ActivityStatus::Draft)
        ->and($activity->locations()->sole()->county_id)->toBe($county->id)
        ->and($activity->documents()->count())->toBe(1);
});

it('builds the team, submits, and lets the CEO approve from the queue', function () {
    $cos = chiefOfStaff();
    $activity = capturedActivity($cos, [], []);
    $officers = Staff::factory()->count(2)->create();

    Livewire::actingAs($cos)->test(Show::class, ['activity' => $activity])
        ->set('showAddStaff', true)
        ->set('selectedStaff', $officers->pluck('id')->all())
        ->call('addStaff')
        ->assertHasNoErrors()
        ->call('submitForDecision');

    expect($activity->fresh()->status)->toBe(ActivityStatus::AwaitingDecision)
        ->and($activity->participants()->count())->toBe(2);

    Livewire::actingAs(ceo())->test(DecisionQueue::class)
        ->assertSee($activity->title)
        ->call('open', $activity->id, 'returned')
        ->call('decide')
        ->assertHasErrors('comment')
        ->call('open', $activity->id, 'approved')
        ->call('decide')
        ->assertHasNoErrors();

    expect($activity->fresh()->status)->toBe(ActivityStatus::Approved);
});

it('asks for a reason when changing an approved team, and records the amendment', function () {
    $cos = chiefOfStaff();
    $activity = capturedActivity($cos);
    app(ActivityLifecycle::class)->recordDecision($activity, DecisionType::Approved, null, 'M/1', today(), UploadedFile::fake()->create('a.pdf'), $cos);
    $extra = Staff::factory()->create();

    $component = Livewire::actingAs($cos)->test(Show::class, ['activity' => $activity->fresh()])
        ->set('selectedStaff', [$extra->id])
        ->call('addStaff')
        ->assertHasErrors('reason');

    $component->set('reason', 'Replacing an officer on leave')->call('addStaff')->assertHasNoErrors();

    expect($activity->amendments()->count())->toBe(1)
        ->and($activity->fresh()->status)->toBe(ActivityStatus::Approved); // one person is within tolerance
});

it('filters the register and shows the count', function () {
    $cos = chiefOfStaff();
    capturedActivity($cos, ['title' => 'Kakamega audit']);
    capturedActivity($cos, ['title' => 'Nyeri training']);

    Livewire::actingAs($cos)->test(Register::class)
        ->assertSee('Showing 2 of 2')
        ->set('search', 'Kakamega')
        ->assertSee('Kakamega audit')
        ->assertDontSee('Nyeri training')
        ->assertSee('Showing 1 of 2');
});

it('lets the Chief of Staff add a DSA rate that end-dates the previous one (MD-05)', function () {
    $cos = chiefOfStaff();
    DsaRate::create(['job_grade' => 'NGCDF 5', 'destination_category' => 'Cities', 'amount' => '10000.00', 'effective_from' => '2024-07-01']);

    Livewire::actingAs($cos)->test(Reference::class)
        ->call('setTab', 'rates')
        ->set('rateGrade', 'ngcdf 5')
        ->set('rateDestination', 'Cities')
        ->set('rateAmount', '12,000')
        ->set('rateFrom', '2026-07-01')
        ->call('addRate')
        ->assertHasNoErrors();

    expect(DsaRate::orderBy('id')->first()->effective_to->toDateString())->toBe('2026-06-30')
        ->and(DsaRate::latest('id')->first()->amount)->toBe('12000.00');
});

it('merges a duplicate department and moves its staff', function () {
    $cos = chiefOfStaff();
    $keep = Department::factory()->create(['name' => 'Finance & Accounts Dept']);
    $dup = Department::factory()->create(['name' => 'Finance and Accounts']);
    $officer = Staff::factory()->create(['department_id' => $dup->id]);

    Livewire::actingAs($cos)->test(Reference::class)
        ->call('openMerge', $dup->id)
        ->set('mergeInto', (string) $keep->id)
        ->call('merge')
        ->assertHasNoErrors();

    expect($officer->fresh()->department_id)->toBe($keep->id)
        ->and($dup->fresh()->is_active)->toBeFalse();
});

it('imports the HR staff list and updates by PF number (MD-01)', function () {
    $cos = chiefOfStaff();
    Staff::factory()->create(['staff_number' => 'PF001', 'name' => 'Old Name']);

    $csv = "PF Number,Name,Designation,Job Grade,Department,Duty Station,Email,Phone,Active\nPF001,New Name,Officer,NGCDF 6,ICT,Headquarters,,,Yes\nPF002,Second Person,Clerk,NGCDF 8,Procurement,Headquarters,,,Yes\n";
    $file = UploadedFile::fake()->createWithContent('staff.csv', $csv);

    Livewire::actingAs($cos)->test(StaffList::class)->set('file', $file)->call('import')->assertHasNoErrors()->assertSet('importErrors', []);

    expect(Staff::where('staff_number', 'PF001')->value('name'))->toBe('New Name')
        ->and(Staff::where('staff_number', 'PF002')->value('job_grade'))->toBe('NGCDF 8');
});

it('keeps to one active account per role and shows the one-time password once (CF-01)', function () {
    $cos = chiefOfStaff();

    Livewire::actingAs($cos)->test(Users::class)
        ->call('openCreate', User::ROLE_CEO)
        ->set('name', 'The CEO')->set('email', 'ceo@ngcdf.go.ke')
        ->call('create')
        ->assertHasNoErrors()
        ->assertNotSet('oneTimePassword', null)
        ->call('openCreate', User::ROLE_CEO)
        ->set('name', 'Second CEO')->set('email', 'ceo2@ngcdf.go.ke')
        ->call('create')
        ->assertHasErrors('role');

    expect(User::role(User::ROLE_CEO)->count())->toBe(1);
});

it('takes a submission through a magic link as a Draft for review', function () {
    config(['activities.submission_links' => true]);
    Route::get('/submit/{token}', Submit::class)->name('submit.show');
    Route::get('/submit/{token}/template', ParticipantTemplateController::class)->name('submit.template');

    $cos = chiefOfStaff();
    [$link, $token] = app(SubmissionLinkService::class)->create(['label' => 'Q2 plan', 'recipient_name' => 'HOD PPME', 'expires_at' => now()->addDays(7), 'max_submissions' => 1], $cos);

    $csv = "Staff Number,Name,Department,Designation,Job Grade,Office,Region,Role,Days,External,Organisation,External Category,Email,Phone\nPF900,Mary Atieno,Projects Planning & M&E,Officer,NGCDF 5,Headquarters,Nyanza,Team lead,3,No,,,,\n,Hon. B,,,,,,Member,2,Yes,National Assembly,MP,,\n";
    $bad = "Staff Number,Name,Role\nPF1,,Member\n";

    $component = Livewire::test(Submit::class, ['token' => $token])
        ->assertSee('Q2 plan')
        ->set('participantList', UploadedFile::fake()->createWithContent('bad.csv', $bad))
        ->assertSet('listErrors', ['Row 2: Name is required.'])
        ->set('title', 'Proposal review, Kisumu')
        ->set('purpose', 'Review proposals')
        ->set('activity_category_id', (string) ActivityCategory::query()->value('id'))
        ->set('organising_department_id', (string) Department::query()->value('id'))
        ->set('start_date', today()->addDays(10)->toDateString())
        ->set('days', 3)
        ->set('county_id', (string) County::where('name', 'Kisumu')->value('id'))
        ->set('memo', UploadedFile::fake()->create('memo.pdf', 20, 'application/pdf'))
        ->set('participantList', UploadedFile::fake()->createWithContent('list.csv', $csv))
        ->assertSet('listErrors', [])
        ->call('submit')
        ->assertHasNoErrors();

    $activity = Activity::sole();
    expect($component->get('submittedReference'))->toBe($activity->reference)
        ->and($activity->status)->toBe(ActivityStatus::Draft)
        ->and($activity->submission_link_id)->toBe($link->id)
        ->and($activity->staff_count)->toBe(1)
        ->and($activity->external_count)->toBe(1)
        ->and(Staff::where('staff_number', 'PF900')->value('source'))->toBe('submission')
        ->and($cos->fresh()->unreadNotifications)->toHaveCount(1);

    // Used up: the page now says the link is not available and shows no form.
    Livewire::test(Submit::class, ['token' => $token])->assertSee('This link is not available')->assertDontSee('Q2 plan');
    Livewire::test(Submit::class, ['token' => str_repeat('a', 48)])->assertSee('This link is not available');
});

it('manages submission links when enabled', function () {
    config(['activities.submission_links' => true]);
    Route::get('/submit/{token}', Submit::class)->name('submit.show');

    Livewire::actingAs(chiefOfStaff())->test(Links::class)
        ->call('edit')
        ->set('label', 'PPME plan')->set('recipient_name', 'HOD PPME')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNotSet('freshUrl', null);

    $link = SubmissionLink::sole();
    $old = $link->token_hash;
    Livewire::actingAs(chiefOfStaff())->test(Links::class)->call('regenerate', $link->id);
    expect($link->fresh()->token_hash)->not->toBe($old);
});
