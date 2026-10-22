<?php

use App\Enums\BudgetStatus;
use App\Enums\ParticipantRole;
use App\Models\Activity;
use App\Models\ActivityCategory;
use App\Models\ActivityDocument;
use App\Models\BudgetLine;
use App\Models\County;
use App\Models\Department;
use App\Models\DepartmentBudget;
use App\Models\PrivacyConsent;
use App\Models\Staff;
use App\Models\User;
use App\Services\ActivityEditor;
use App\Services\DocumentStore;
use Database\Seeders\GeographySeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->seed([RolesAndPermissionsSeeder::class, GeographySeeder::class, ReferenceDataSeeder::class]);
    })
    ->in('Feature');

/**
 * A browser that has agreed to the Data Privacy Statement as a visitor: returns the consent
 * token for the privacy cookie (`withCookie(config('privacy.cookie'), ...)`).
 */
function visitorPrivacyConsent(): string
{
    $token = bin2hex(random_bytes(32));
    PrivacyConsent::create([
        'consent_type' => PrivacyConsent::TYPE_ANONYMOUS,
        'consent_token_hash' => hash('sha256', $token),
        'privacy_statement_version' => config('privacy.statement.version'),
        'consented_at' => now(),
    ]);

    return $token;
}

function ceo(): User
{
    return User::factory()->ceo()->create();
}

function chiefOfStaff(): User
{
    return User::factory()->chiefOfStaff()->create();
}

function assistant(): User
{
    return User::factory()->assistant()->create();
}

/**
 * An activity captured the way the Office of the CEO does it: details, one
 * location, a memo, and the given staff (or one new staff member).
 *
 * @param  array<string, mixed>  $overrides
 * @param  list<Staff>|null  $team
 */
function capturedActivity(User $by, array $overrides = [], ?array $team = null): Activity
{
    $editor = app(ActivityEditor::class);

    $activity = $editor->create(array_merge([
        'title' => 'Proposal review, Western Region',
        'purpose' => 'Review FY proposals',
        'activity_category_id' => ActivityCategory::query()->value('id'),
        'organising_department_id' => Department::query()->value('id'),
        'start_date' => today()->addDays(30)->toDateString(),
        'days' => 3,
    ], $overrides), $by);

    $county = County::query()->where('name', 'Kakamega')->firstOrFail();
    $editor->addLocation($activity, ['county_id' => $county->id, 'region_id' => $county->constituencies()->value('region_id')], $by);

    app(DocumentStore::class)->store($activity, UploadedFile::fake()->create('memo.pdf', 20, 'application/pdf'), ActivityDocument::KIND_MEMO, $by);

    $team ??= [Staff::factory()->create()];
    $editor->addStaff($activity, collect($team)->pluck('id')->all(), ParticipantRole::Member, $by);

    return $activity->fresh();
}

function headOfDepartment(?Department $department = null): User
{
    return User::factory()->headOf($department ?? Department::query()->where('name', 'ICT')->firstOrFail())->create();
}

/**
 * A memo a Head of Department prepares for their own department: details,
 * the introduction, the venue, one officer and the signed memo, saved as a
 * Draft (not yet submitted) with nothing blocking its submission.
 *
 * @param  array<string, mixed>  $overrides
 */
function departmentMemo(User $hod, array $overrides = []): Activity
{
    $editor = app(ActivityEditor::class);

    $memo = $editor->create(array_merge([
        'title' => 'ICT Department Quarterly Systems Review and Staff Training',
        'purpose' => 'Review the performance of departmental systems and train designated staff.',
        'justification' => 'The departmental systems are due for their quarterly review.',
        'activity_category_id' => ActivityCategory::query()->value('id'),
        'start_date' => today()->addDays(30)->toDateString(),
        'days' => 1,
        'requested_budget' => '250000.00',
    ], $overrides), $hod);

    $editor->addLocation($memo, ['venue' => 'NGCDF Board headquarters'], $hod);
    app(DocumentStore::class)->store($memo, UploadedFile::fake()->create('memo.pdf', 20, 'application/pdf'), ActivityDocument::KIND_MEMO, $hod);
    $editor->addStaff($memo, [Staff::factory()->create(['department_id' => $hod->department_id])->id], ParticipantRole::Member, $hod);

    return $memo->fresh();
}

/**
 * A department budget the CEO has approved, with one line: what the budget
 * position counts. Returns the line.
 */
function approvedBudgetLine(Department $department, string $financialYear, string $amount, ?string $code = null, string $name = 'Domestic travel'): BudgetLine
{
    $budget = DepartmentBudget::query()->firstOrNew(['department_id' => $department->id, 'financial_year' => $financialYear]);
    $budget->forceFill(['status' => BudgetStatus::Approved, 'approved_at' => now()])->save();

    return $budget->lines()->create(['department_id' => $department->id, 'financial_year' => $financialYear, 'code' => $code, 'name' => $name, 'amount' => $amount]);
}
