<?php

use App\Enums\ActivityStatus;
use App\Enums\EmploymentType;
use App\Models\Activity;
use App\Models\ActivityCost;
use App\Models\County;
use App\Models\DsaRate;
use App\Models\Staff;
use App\Services\ActivityEditor;
use App\Services\AppSettings;
use App\Services\DsaCalculator;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(SettingsSeeder::class);
});

/** The computed DSA line of the activity's only staff participant. */
function dsaLine(Activity $activity): ?ActivityCost
{
    return $activity->costs()->where('is_computed', true)->first();
}

it('costs each job grade at its approved standard rate', function (string $grade, string $rate) {
    $activity = capturedActivity(chiefOfStaff(), ['days' => 3], [Staff::factory()->create(['job_grade' => $grade])]);

    expect(dsaLine($activity))->unit_rate->toBe($rate)->quantity->toBe('2.00');
})->with([
    ['1', '18200.00'],
    ['2', '16800.00'],
    ['3', '16800.00'],
    ['4', '14000.00'],
    ['5', '14000.00'],
    ['6', '11200.00'],
    ['7', '11200.00'],
    ['8', '11200.00'],
    ['9', '6300.00'],
    ['10', '6300.00'],
]);

it('finds the scale\'s rate for a grade recorded with an older label', function (string $recorded) {
    $activity = capturedActivity(chiefOfStaff(), ['days' => 3], [Staff::factory()->create(['job_grade' => $recorded])]);

    expect(dsaLine($activity)->unit_rate)->toBe('14000.00');
})->with(['NGCDF 5', 'Scale 5', 'Job Group 5']);

it('costs casuals and attachees without a job grade at the INTERN rate, and a reclassified casual at their grade', function () {
    $casual = Staff::factory()->create(['staff_number' => null, 'job_grade' => null, 'employment_type' => EmploymentType::Casual]);
    $attachee = Staff::factory()->create(['staff_number' => null, 'job_grade' => null, 'employment_type' => EmploymentType::Attachee]);
    $reclassified = Staff::factory()->create(['staff_number' => null, 'job_grade' => '7', 'employment_type' => EmploymentType::Casual]);

    $activity = capturedActivity(chiefOfStaff(), ['days' => 3], [$casual, $attachee, $reclassified]);

    $rates = $activity->costs()->where('is_computed', true)->with('participant')->get()->mapWithKeys(fn ($c) => [$c->participant->staff_id => $c->unit_rate]);
    expect($rates->all())->toBe([$casual->id => '4000.00', $attachee->id => '4000.00', $reclassified->id => '11200.00']);
});

it('keeps the same rate wherever the activity is, and never uses the retired destination rates', function () {
    County::query()->where('name', 'Nairobi City')->update(['dsa_destination_category' => 'Cities']);
    DsaRate::create(['job_grade' => '5', 'destination_category' => 'Cities', 'amount' => '99000.00', 'effective_from' => '2026-08-01']);
    DsaRate::create(['job_grade' => '5', 'destination_category' => 'Other towns', 'amount' => '1000.00', 'effective_from' => '2026-08-01']);
    $cos = chiefOfStaff();
    $activity = capturedActivity($cos, ['days' => 3], [Staff::factory()->create(['job_grade' => '5'])]);
    $inKakamega = dsaLine($activity)->unit_rate;

    $nairobi = County::query()->where('name', 'Nairobi City')->firstOrFail();
    $activity->locations()->delete();
    app(ActivityEditor::class)->addLocation($activity->fresh(), ['county_id' => $nairobi->id], $cos);

    expect($inKakamega)->toBe('14000.00')
        ->and(dsaLine($activity->fresh())->unit_rate)->toBe('14000.00');
});

it('adds no DSA and warns when a participant has no job grade or one without a rate', function (?string $grade, string $shown) {
    $officer = Staff::factory()->create(['name' => 'Ann Example', 'job_grade' => $grade]);
    $activity = capturedActivity(chiefOfStaff(), ['days' => 3], [$officer]);

    $warnings = app(DsaCalculator::class)->recalculate($activity);

    expect($warnings)->toBe(["No DSA rate for Ann Example (job grade {$shown})."])
        ->and(dsaLine($activity))->toBeNull();
})->with([
    'no grade' => [null, 'not set'],
    'unknown grade' => ['42', '42'],
]);

it('does not recalculate a completed activity, but a draft picks up a new rate when recalculated', function () {
    $officer = Staff::factory()->create(['job_grade' => '6']);
    $completed = capturedActivity(chiefOfStaff(), ['days' => 3], [$officer]);
    $draft = capturedActivity(chiefOfStaff(), ['days' => 3], [$officer]);
    $completed->forceFill(['status' => ActivityStatus::Completed])->save();
    DsaRate::query()->standard()->where('job_grade', '6')->update(['amount' => '12000.00']);

    $warnings = app(DsaCalculator::class)->recalculate($completed);
    app(DsaCalculator::class)->recalculate($draft);

    expect($warnings)->toBe(['DSA is not recalculated once an activity is completed, closed, declined or cancelled; its recorded amounts are kept.'])
        ->and(dsaLine($completed))->unit_rate->toBe('11200.00')->estimated_amount->toBe('22400.00')
        ->and(dsaLine($draft))->unit_rate->toBe('12000.00')->estimated_amount->toBe('24000.00');
});

it('seeds the approved rates once, puts a changed amount back, and leaves later rates, retired rates, settings and costs alone', function () {
    $activity = capturedActivity(chiefOfStaff(), ['days' => 3], [Staff::factory()->create(['job_grade' => '4'])]);
    DsaRate::query()->standard()->where('job_grade', '1')->update(['amount' => '1.00']);
    DsaRate::query()->standard()->where('job_grade', '2')->update(['effective_to' => '2027-06-30']);
    $later = DsaRate::create(['job_grade' => '2', 'amount' => '17500.00', 'effective_from' => '2027-07-01']);
    $retired = DsaRate::create(['job_grade' => '3', 'destination_category' => 'Cities', 'amount' => '14000.00', 'effective_from' => '2026-07-01']);
    ActivityCost::query()->whereKey(dsaLine($activity)->id)->update(['estimated_amount' => '30000.00']);
    app(AppSettings::class)->set('late_notice_working_days', 10);

    $this->seed(SettingsSeeder::class);
    $this->seed(SettingsSeeder::class);

    $standard = DsaRate::query()->standard()->whereDate('effective_from', '2026-07-01');
    expect($standard->count())->toBe(11)
        ->and((clone $standard)->pluck('amount', 'job_grade')->all())->toBe([
            '1' => '18200.00', '2' => '16800.00', '3' => '16800.00', '4' => '14000.00',
            '5' => '14000.00', '6' => '11200.00', '7' => '11200.00', '8' => '11200.00',
            '9' => '6300.00', '10' => '6300.00', 'INTERN' => '4000.00',
        ])
        ->and((clone $standard)->where('job_grade', '2')->value('effective_to'))->not->toBeNull()
        ->and($later->fresh()->amount)->toBe('17500.00')
        ->and($retired->fresh()->amount)->toBe('14000.00')
        ->and(dsaLine($activity)->estimated_amount)->toBe('30000.00')
        ->and(app(AppSettings::class)->int('late_notice_working_days'))->toBe(10);
});
