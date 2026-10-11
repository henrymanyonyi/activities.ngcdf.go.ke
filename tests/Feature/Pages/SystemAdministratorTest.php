<?php

use App\Models\User;
use App\Services\ActivityEditor;
use App\Services\ActivityLifecycle;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

it('holds only settings, user account, log and export permissions', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->getAllPermissions()->pluck('name')->sort()->values()->all())
        ->toBe(collect(RolesAndPermissionsSeeder::MATRIX[User::ROLE_ADMIN])->sort()->values()->all())
        ->and($admin->can('activities.view'))->toBeFalse();
});

it('lands on settings after sign-in, and everyone else on the dashboard', function () {
    $this->actingAs(User::factory()->admin()->create())->get(route('home'))->assertRedirect(route('settings.reference'));
    $this->actingAs(ceo())->get(route('home'))->assertRedirect(route('dashboard'));
    $this->get('/')->assertRedirect('/home');
});

it('opens settings, staff, user accounts and the access and audit log, and exports the log', function () {
    $admin = User::factory()->admin()->create();

    foreach ([route('settings.reference'), route('staff.index'), route('users.index'), route('reports.show', 'access-audit')] as $url) {
        $this->actingAs($admin)->get($url)->assertOk()->assertSee('Restricted');
    }

    $this->actingAs($admin)->get(route('audit.index'))->assertRedirect(route('reports.show', 'access-audit'));
    $this->actingAs($admin)->get(route('reports.export', ['access-audit', 'print']))->assertOk()->assertSee('RESTRICTED')->assertSee($admin->name);
});

it('sees no activity, dashboard or activity report, on screen or by export', function () {
    $activity = capturedActivity(chiefOfStaff());
    $admin = User::factory()->admin()->create();

    foreach (['dashboard', 'decisions.index', 'activities.index', 'activities.create', 'calendar', 'field-today', 'directives.index', 'participation', 'department-costs', 'reports.index'] as $route) {
        $this->actingAs($admin)->get(route($route))->assertForbidden();
    }

    $this->actingAs($admin)->get(route('activities.show', $activity))->assertForbidden();
    $this->actingAs($admin)->get(route('reports.show', 'activity-register'))->assertNotFound();
    $this->actingAs($admin)->get(route('reports.export', ['activity-register', 'xlsx']))->assertForbidden();
});

it('cannot change activities through the services, and receives no alerts', function () {
    $admin = User::factory()->admin()->create();
    $cos = chiefOfStaff();
    $activity = capturedActivity($cos);

    expect(fn () => app(ActivityEditor::class)->update($activity, ['title' => 'x'], $admin))->toThrow(AuthorizationException::class);

    ceo();
    app(ActivityLifecycle::class)->submitForDecision($activity, $cos);

    expect($admin->notifications()->count())->toBe(0);
});
