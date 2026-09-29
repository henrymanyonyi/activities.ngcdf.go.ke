<?php

use App\Enums\ParticipantRole;
use App\Models\Activity;
use App\Models\ActivityCategory;
use App\Models\ActivityDocument;
use App\Models\County;
use App\Models\Department;
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
