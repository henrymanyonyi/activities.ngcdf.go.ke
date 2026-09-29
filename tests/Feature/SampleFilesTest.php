<?php

use App\Enums\ActivityStatus;
use App\Enums\DecisionType;
use App\Models\CostCategory;
use App\Models\Department;
use App\Models\DsaRate;
use App\Models\Staff;
use App\Services\ActivityEditor;
use App\Services\ActivityLifecycle;
use App\Services\DsaCalculator;
use App\Services\ParticipantListImporter;
use App\Services\StaffImporter;
use Illuminate\Support\Facades\Storage;

// The files docs/TESTING-GUIDE.pdf tells testers to upload must keep working.
it('imports the sample HR staff list into the seeded departments', function () {
    $departments = Department::count();

    $result = app(StaffImporter::class)->import(base_path('docs/samples/hr-staff-list.csv'));

    expect($result['errors'])->toBe([])
        ->and($result['created'])->toBe(16)
        ->and(Department::count())->toBe($departments)
        ->and(Staff::where('is_active', true)->count())->toBe(15)
        ->and(Staff::where('staff_number', 'PF1008')->first()->job_grade)->toBe('NGCDF 4');
});

it('reads the sample participant list without errors', function () {
    $parsed = app(ParticipantListImporter::class)->parse(base_path('docs/samples/participant-list.csv'));

    expect($parsed['errors'])->toBe([])->and($parsed['rows'])->toHaveCount(4);
});

it('matches the figures and steps in the testing guide walkthrough', function () {
    Storage::fake('local');
    app(StaffImporter::class)->import(base_path('docs/samples/hr-staff-list.csv'));
    foreach (['NGCDF 5' => '8400', 'NGCDF 6' => '7000', 'NGCDF 8' => '5600'] as $grade => $rate) {
        DsaRate::create(['job_grade' => $grade, 'destination_category' => 'Other towns', 'amount' => $rate, 'effective_from' => '2026-07-01']);
    }

    $cos = chiefOfStaff();
    $team = Staff::whereIn('staff_number', ['PF1002', 'PF1003', 'PF1012'])->get()->all();
    $activity = capturedActivity($cos, ['start_date' => today()->toDateString(), 'days' => 3], $team);
    $editor = app(ActivityEditor::class);

    expect($activity->estimated_total)->toBe('42000.00')->and($activity->is_late_notice)->toBeTrue();

    $cat = fn ($code) => CostCategory::where('code', $code)->value('id');
    $editor->savePlannedCost($activity, ['cost_category_id' => $cat('fuel'), 'estimated_amount' => '18,500'], $cos);
    $peter = $activity->participants()->whereHas('staff', fn ($q) => $q->where('staff_number', 'PF1002'))->first();
    $editor->savePlannedCost($activity, ['cost_category_id' => $cat('travel'), 'activity_participant_id' => $peter->id, 'estimated_amount' => '12000', 'travel_mode' => 'air'], $cos);
    expect($activity->fresh()->estimated_total)->toBe('72500.00');

    $amina = $activity->costs()->where('is_computed', true)->whereHas('participant.staff', fn ($q) => $q->where('staff_number', 'PF1003'))->first();
    app(DsaCalculator::class)->override($amina, '10500', 'Returns a day early');
    app(DsaCalculator::class)->recalculate($activity);
    expect($amina->fresh()->estimated_amount)->toBe('10500.00');

    $life = app(ActivityLifecycle::class);
    $life->submitForDecision($activity, $cos);
    $life->decide($activity, DecisionType::Approved, null, ceo());
    $life->confirmCommencement($activity, $cos);
    expect($activity->status)->toBe(ActivityStatus::InProgress);
});
