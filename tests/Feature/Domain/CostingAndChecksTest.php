<?php

use App\Enums\DecisionType;
use App\Enums\ExternalCategory;
use App\Enums\ParticipantRole;
use App\Exceptions\ActivityWorkflowException;
use App\Models\CostCategory;
use App\Models\DsaRate;
use App\Models\Staff;
use App\Services\ActivityCosting;
use App\Services\ActivityEditor;
use App\Services\ActivityLifecycle;
use App\Services\AppSettings;
use App\Services\DsaCalculator;
use App\Services\ParticipantChecks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    DsaRate::create(['job_grade' => 'NGCDF 5', 'destination_category' => 'Other towns', 'amount' => '8400.00', 'effective_from' => '2020-07-01']);
});

it('computes DSA from the rate in force × nights (CB-01, BR-02)', function () {
    $activity = capturedActivity(chiefOfStaff(), ['days' => 3]);

    $line = $activity->costs()->where('is_computed', true)->sole();

    expect($line->estimated_amount)->toBe('16800.00') // 8,400 × 2 nights
        ->and($activity->estimated_total)->toBe('16800.00');
});

it('uses the historical rate for past activities (MD-05)', function () {
    DsaRate::query()->update(['effective_to' => today()->subDays(10)]);
    DsaRate::create(['job_grade' => 'NGCDF 5', 'destination_category' => 'Other towns', 'amount' => '9000.00', 'effective_from' => today()->subDays(9)]);

    $old = capturedActivity(chiefOfStaff(), ['start_date' => today()->subDays(20)->toDateString(), 'days' => 2]);
    $new = capturedActivity(chiefOfStaff(), ['days' => 2]);

    expect($old->costs()->sole()->estimated_amount)->toBe('8400.00')
        ->and($new->costs()->sole()->estimated_amount)->toBe('9000.00');
});

it('keeps an override with its justification when DSA is recalculated (CB-01)', function () {
    $cos = chiefOfStaff();
    $activity = capturedActivity($cos, ['days' => 3]);
    $line = $activity->costs()->sole();

    app(DsaCalculator::class)->override($line, '10000', 'Officer returns a day early');
    app(DsaCalculator::class)->recalculate($activity);

    expect($line->fresh()->estimated_amount)->toBe('10000.00')
        ->and($line->fresh()->computed_amount)->toBe('16800.00')
        ->and($line->fresh()->isOverridden())->toBeTrue();
});

it('totals DSA + travel + other and splits shared cost per head, with externals optional (BR-03, Q4)', function () {
    $cos = chiefOfStaff();
    $editor = app(ActivityEditor::class);
    $activity = capturedActivity($cos, ['days' => 1]); // 0 nights: no DSA
    $editor->addExternal($activity, ['external_name' => 'Hon. A', 'external_category' => ExternalCategory::Mp], ParticipantRole::Member, $cos);

    $venue = CostCategory::where('code', 'venue')->value('id');
    $editor->savePlannedCost($activity, ['cost_category_id' => $venue, 'estimated_amount' => '10,000.00'], $cos);

    $activity->refresh();
    $costing = app(ActivityCosting::class);
    expect($activity->estimated_total)->toBe('10000.00')
        ->and($costing->breakdown($activity)['share'])->toBe(500000); // 2 heads

    $activity->update(['count_externals_in_per_head' => false]);
    expect($costing->breakdown($activity->fresh())['share'])->toBe(1000000);
});

it('flags overlapping approved activities and requires a reason (PT-04, BR-04)', function () {
    $cos = chiefOfStaff();
    $officer = Staff::factory()->create();

    $first = capturedActivity($cos, ['start_date' => today()->addDays(30)->toDateString(), 'days' => 3], [$officer]);
    app(ActivityLifecycle::class)->recordDecision($first, DecisionType::Approved, null, 'M/9', today(), UploadedFile::fake()->create('a.pdf'), $cos);

    $second = capturedActivity($cos, ['start_date' => today()->addDays(31)->toDateString()], []);

    expect(fn () => app(ActivityEditor::class)->addStaff($second, [$officer->id], ParticipantRole::Member, $cos))
        ->toThrow(ActivityWorkflowException::class, 'already on another');

    app(ActivityEditor::class)->addStaff($second, [$officer->id], ParticipantRole::Member, $cos, [$officer->id => 'CEO asked for both']);
    expect($second->participants()->where('staff_id', $officer->id)->value('conflict_reason'))->toBe('CEO asked for both');
});

it('reports cumulative field days against the threshold (PT-06)', function () {
    app(AppSettings::class)->set('field_days_threshold_quarter', 4);
    $cos = chiefOfStaff();
    $officer = Staff::factory()->create();

    $first = capturedActivity($cos, ['start_date' => today()->addDays(30)->toDateString(), 'days' => 3], [$officer]);
    app(ActivityLifecycle::class)->recordDecision($first, DecisionType::Approved, null, 'M/10', today(), UploadedFile::fake()->create('a.pdf'), $cos);

    $second = capturedActivity($cos, ['start_date' => today()->addDays(35)->toDateString(), 'days' => 2], []);
    $flags = app(ParticipantChecks::class)->fieldDayFlags($officer, $second);

    expect($flags['quarter'])->toBe(5)->and($flags['over_quarter'])->toBeTrue();
});

it('sends an approved activity back to the CEO when planned cost rises beyond tolerance (BR-10)', function () {
    $cos = chiefOfStaff();
    $activity = capturedActivity($cos, ['days' => 3]); // 16,800 DSA
    app(ActivityLifecycle::class)->recordDecision($activity, DecisionType::Approved, null, 'M/11', today(), UploadedFile::fake()->create('a.pdf'), $cos);

    $returned = app(ActivityEditor::class)->savePlannedCost($activity->fresh(), [
        'cost_category_id' => CostCategory::where('code', 'venue')->value('id'),
        'estimated_amount' => '5000',
    ], $cos, amendmentReason: 'Venue now charged');

    expect($returned)->toBeTrue()
        ->and($activity->fresh()->status->value)->toBe('awaiting_decision');
});
