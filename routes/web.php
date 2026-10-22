<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\MemoDocumentController;
use App\Http\Controllers\ParticipantTemplateController;
use App\Http\Controllers\PrivacyStatementController;
use App\Http\Controllers\ReportExportController;
use App\Livewire;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/home');

/*
| NG-CDF Board Data Privacy Statement: public, no sign-in (see PrivacyConsentService).
*/
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/privacy-statement', [PrivacyStatementController::class, 'show'])->name('privacy.statement');
    Route::get('/privacy-statement/download', [PrivacyStatementController::class, 'download'])->name('privacy.statement.download');
    Route::post('/privacy-statement/consent', [PrivacyStatementController::class, 'consent'])->name('privacy.statement.consent');
});

/*
| The consent screen EnsurePrivacyConsent holds a signed-in user on until
| they agree to the statement version in force.
*/
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified', 'active', 'two_factor'])->group(function () {
    Route::get('/privacy-consent', [PrivacyStatementController::class, 'required'])->name('privacy.consent.show');
    Route::post('/privacy-consent', [PrivacyStatementController::class, 'accept'])->name('privacy.consent.accept');
});

/*
| The authorised users only (FRD CF-01..CF-05): signed in, active, two-factor
| confirmed. Every action inside is also checked against the permission
| matrix by the services (CF-02).
*/
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified', 'active', 'two_factor', 'privacy.consent'])->group(function () {
    // Where sign-in lands: the dashboard, or for the System Administrator the first page their role opens.
    Route::get('/home', fn () => redirect()->route(auth()->user()->homeRoute()))->name('home');

    // Each report, and its export, checks the report's own permission: the
    // access and audit log needs audit.view, every other report activities.view.
    Route::get('/reports/{report}', Livewire\Reports\Show::class)->name('reports.show');
    Route::get('/reports/{report}/export/{format}', ReportExportController::class)->middleware('permission:reports.export')->name('reports.export');

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
        Route::get('/templates/participant-list', ParticipantTemplateController::class)->name('templates.participants');
    });

    // Activity memo workflow. A Head of Department sees only their own
    // department's memos (Activity::scopeVisibleTo), checked again in every service.
    Route::get('/memos', Livewire\Memos\Board::class)->middleware('permission:memos.originate|memos.review')->name('memos.index');
    Route::middleware('permission:memos.originate')->group(function () {
        Route::get('/memos/create', Livewire\Activities\Form::class)->name('memos.create');
        Route::get('/memos/{activity}', Livewire\Memos\Show::class)->name('memos.show');
        Route::get('/memos/{activity}/edit', Livewire\Activities\Form::class)->name('memos.edit');
        Route::get('/memos/{activity}/team', Livewire\Memos\Team::class)->name('memos.team');
    });
    // The generated memo and annexes (PDF): the Office of the CEO, or the department's own Head.
    Route::get('/memos/{activity}/document', MemoDocumentController::class)->middleware('permission:activities.view|memos.originate')->name('memos.document');
    Route::get('/documents/{document}', DocumentController::class)->middleware('permission:activities.view|memos.originate')->name('documents.show');

    // Budgeting: the Office of the CEO prepares, only the CEO approves (App\Services\Budgeting checks again).
    Route::middleware('permission:budgets.prepare|budgets.approve')->group(function () {
        Route::get('/budgets', Livewire\Budgets\Index::class)->name('budgets.index');
        Route::get('/budgets/adjustments', Livewire\Budgets\Adjustments::class)->name('budgets.adjustments');
        Route::get('/budgets/{budget}', Livewire\Budgets\Show::class)->name('budgets.show');
    });

    Route::get('/links', Livewire\Links::class)->middleware('permission:links.manage')->name('links.index');
    Route::get('/staff', Livewire\StaffList::class)->middleware('permission:reference.manage|staff.add')->name('staff.index');
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
