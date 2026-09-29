<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ParticipantTemplateController;
use App\Http\Controllers\ReportExportController;
use App\Livewire;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

/*
| The three users only (FRD CF-01..CF-05): signed in, active, two-factor
| confirmed. Every action inside is also checked against the permission
| matrix by the services (CF-02).
*/
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified', 'active', 'two_factor'])->group(function () {
    Route::middleware('permission:activities.view')->group(function () {
        Route::get('/dashboard', Livewire\Dashboard::class)->name('dashboard');
        Route::get('/decisions', Livewire\DecisionQueue::class)->name('decisions.index');

        Route::get('/activities', Livewire\Activities\Register::class)->name('activities.index');
        Route::get('/activities/create', Livewire\Activities\Form::class)->middleware('permission:activities.manage')->name('activities.create');
        Route::get('/activities/{activity}', Livewire\Activities\Show::class)->name('activities.show');
        Route::get('/activities/{activity}/edit', Livewire\Activities\Form::class)->middleware('permission:activities.manage')->name('activities.edit');

        Route::get('/calendar', Livewire\Calendar::class)->name('calendar');
        Route::get('/in-the-field', Livewire\FieldToday::class)->name('field-today');
        Route::get('/directives', Livewire\Directives::class)->name('directives.index');
        Route::get('/participation', Livewire\Participation::class)->name('participation');
        Route::get('/department-costs', Livewire\DepartmentCosts::class)->name('department-costs');

        Route::get('/reports', Livewire\Reports\Index::class)->name('reports.index');
        Route::get('/reports/{report}', Livewire\Reports\Show::class)->name('reports.show');
        Route::get('/reports/{report}/export/{format}', ReportExportController::class)->middleware('permission:reports.export')->name('reports.export');

        Route::get('/documents/{document}', DocumentController::class)->name('documents.show');
        Route::get('/templates/participant-list', ParticipantTemplateController::class)->name('templates.participants');
    });

    Route::get('/links', Livewire\Links::class)->middleware('permission:links.manage')->name('links.index');
    Route::get('/staff', Livewire\StaffList::class)->middleware('permission:reference.manage')->name('staff.index');
    Route::get('/settings', Livewire\Settings\Reference::class)->middleware('permission:reference.manage')->name('settings.reference');
    Route::get('/users', Livewire\Users::class)->middleware('permission:users.manage')->name('users.index');
    Route::get('/audit', fn () => redirect()->route('reports.show', 'access-audit'))->middleware('permission:audit.view')->name('audit.index');
});

/*
| Magic-link intake (off unless ACTIVITIES_SUBMISSION_LINKS=true). The page
| shows nothing but its own form; see App\Livewire\Submit.
*/
if (config('activities.submission_links')) {
    Route::middleware('throttle:30,1')->group(function () {
        Route::get('/submit/{token}', Livewire\Submit::class)->name('submit.show');
        Route::get('/submit/{token}/template', ParticipantTemplateController::class)->name('submit.template');
    });
}
