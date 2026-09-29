<?php

use App\Enums\DecisionType;
use App\Models\AccessLog;
use App\Models\User;
use App\Reports\ReportCatalog;
use App\Services\ActivityLifecycle;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

dataset('pages', [
    'dashboard' => ['dashboard'],
    'decisions' => ['decisions.index'],
    'register' => ['activities.index'],
    'calendar' => ['calendar'],
    'field today' => ['field-today'],
    'directives' => ['directives.index'],
    'participation' => ['participation'],
    'department costs' => ['department-costs'],
    'reports' => ['reports.index'],
]);

it('renders every page for all three users', function (string $route) {
    $cos = chiefOfStaff();
    capturedActivity($cos);

    foreach ([ceo(), $cos, assistant()] as $user) {
        $this->actingAs($user)->get(route($route))->assertOk()->assertSee('Restricted');
    }
})->with('pages');

it('renders the activity page at each stage for each user', function () {
    $cos = chiefOfStaff();
    $ceo = ceo();
    $activity = capturedActivity($cos, ['start_date' => today()->toDateString()]);

    $this->actingAs($cos)->get(route('activities.show', $activity))->assertOk()->assertSee('Submit to CEO');

    app(ActivityLifecycle::class)->submitForDecision($activity, $cos);
    $this->actingAs($ceo)->get(route('activities.show', $activity))->assertOk()->assertSee('Approve')->assertSee('Decline');
    $this->actingAs(assistant())->get(route('activities.show', $activity))->assertOk()->assertDontSee('Record CEO decision');

    app(ActivityLifecycle::class)->decide($activity, DecisionType::Approved, null, $ceo);
    $this->actingAs($cos)->get(route('activities.show', [$activity, 'tab' => 'team']))->assertOk()->assertSee('Confirm commencement');

    foreach (['overview', 'team', 'costs', 'decisions', 'report', 'history'] as $tab) {
        $this->actingAs($ceo)->get(route('activities.show', [$activity, 'tab' => $tab]))->assertOk();
    }
});

it('renders every standard report', function () {
    capturedActivity(chiefOfStaff());
    $ceo = ceo();

    foreach (ReportCatalog::all()->keys() as $key) {
        $this->actingAs($ceo)->get(route('reports.show', $key))->assertOk();
    }
});

it('enforces the permission matrix on pages (4.1)', function () {
    $ceo = ceo();
    $assistant = assistant();
    $cos = chiefOfStaff();

    // Reference data and user accounts: Chief of Staff only.
    $this->actingAs($cos)->get(route('settings.reference'))->assertOk();
    $this->actingAs($cos)->get(route('staff.index'))->assertOk();
    $this->actingAs($cos)->get(route('users.index'))->assertOk();
    $this->actingAs($ceo)->get(route('settings.reference'))->assertForbidden();
    $this->actingAs($ceo)->get(route('users.index'))->assertForbidden();
    $this->actingAs($assistant)->get(route('staff.index'))->assertForbidden();

    // Audit trail: CEO and Chief of Staff, not the Assistant.
    $this->actingAs($ceo)->get(route('reports.show', 'access-audit'))->assertOk();
    $this->actingAs($assistant)->get(route('reports.show', 'access-audit'))->assertNotFound();

    // Export: not the Assistant.
    $this->actingAs($assistant)->get(route('reports.export', ['activity-register', 'xlsx']))->assertForbidden();
});

it('keeps everyone else out (CF-01)', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));

    $outsider = User::factory()->create();
    $this->actingAs($outsider)->get(route('dashboard'))->assertForbidden();

    $this->get('/register')->assertNotFound();
});

it('requires two-factor authentication when configured (CF-03)', function () {
    config(['activities.require_two_factor' => true]);
    $user = User::factory()->withoutTwoFactor()->ceo()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('profile.show'));
});

it('signs out a deactivated account (CF-04)', function () {
    $user = ceo();
    $user->update(['is_active' => false]);

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
});

it('locks an account after repeated failed sign-ins (CF-04)', function () {
    $user = User::factory()->ceo()->create(['email' => 'ceo@ngcdf.go.ke']);
    config(['activities.lockout_attempts' => 3]);

    foreach (range(1, 3) as $i) {
        $this->post('/login', ['email' => 'ceo@ngcdf.go.ke', 'password' => 'wrong']);
    }

    expect($user->fresh()->isLocked())->toBeTrue();
    $this->post('/login', ['email' => 'ceo@ngcdf.go.ke', 'password' => 'password']);
    $this->assertGuest();
    expect(AccessLog::where('event', 'locked_out')->exists())->toBeTrue();
});

it('logs sign-ins and views of an activity (CF-06)', function () {
    $user = User::factory()->ceo()->create(['email' => 'ceo2@ngcdf.go.ke']);
    $this->post('/login', ['email' => 'ceo2@ngcdf.go.ke', 'password' => 'password']);
    $this->assertAuthenticatedAs($user);

    $activity = capturedActivity(chiefOfStaff());
    $this->get(route('activities.show', $activity))->assertOk();

    expect(AccessLog::where('event', 'sign_in')->where('user_id', $user->id)->exists())->toBeTrue()
        ->and(AccessLog::where('event', 'view')->where('subject_id', $activity->id)->exists())->toBeTrue();
});

it('exports only the filtered records, marked RESTRICTED and logged (SR-03, CF-07)', function () {
    $cos = chiefOfStaff();
    $keep = capturedActivity($cos, ['title' => 'Keep me']);
    capturedActivity($cos, ['title' => 'Filter me out']);

    $response = $this->actingAs($cos)->get(route('reports.export', ['activity-register', 'print', 'search' => 'Keep']));

    $response->assertOk()->assertSee('RESTRICTED: FOR THE OFFICE OF THE CEO ONLY')->assertSee('Keep me')->assertDontSee('Filter me out')->assertSee('Produced by '.$cos->name);
    expect(AccessLog::where('event', 'print')->first()->meta['rows'])->toBe(1);

    $this->actingAs($cos)->get(route('reports.export', ['activity-register', 'xlsx']))->assertOk();
    $this->actingAs($cos)->get(route('reports.export', ['department-field-expenditure', 'pdf']))->assertOk();
});

it('serves attachments decrypted, only to signed-in users (CF-08)', function () {
    $activity = capturedActivity(chiefOfStaff());
    $document = $activity->documents()->first();

    expect(Storage::disk('local')->get($document->path))->not->toContain('%PDF');

    $this->get(route('documents.show', $document))->assertRedirect(route('login'));
    $this->actingAs(ceo())->get(route('documents.show', $document))->assertOk();
});
