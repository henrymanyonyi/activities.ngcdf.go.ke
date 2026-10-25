<?php

use App\Enums\ActivityStatus;
use App\Enums\EmploymentType;
use App\Models\DsaRate;
use App\Models\Staff;
use App\Services\ActivityEditor;
use App\Services\AppSettings;
use App\Services\DsaCalculator;
use App\Support\DsaPeriod;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(SettingsSeeder::class);
});

it('counts activity days and payable nights, with the departure night for an overnight activity', function (string $start, string $end, int $days, int $nights, ?string $departure) {
    $period = DsaPeriod::between(Carbon::parse($start), Carbon::parse($end));

    expect($period->days)->toBe($days)
        ->and($period->nights())->toBe($nights)
        ->and($period->departure()?->toDateString())->toBe($departure)
        ->and($period->end()->toDateString())->toBe($end)
        ->and($period->units(DsaPeriod::BASIS_DAYS))->toBe($days);
})->with([
    '26 Oct to 4 Nov' => ['2026-10-26', '2026-11-04', 10, 11, '2026-10-25'],
    '26 Oct to 28 Oct' => ['2026-10-26', '2026-10-28', 3, 4, '2026-10-25'],
    'same day' => ['2026-10-26', '2026-10-26', 1, 0, null],
]);

it('rejects an end date before the start date and an activity of no days', function () {
    expect(fn () => DsaPeriod::between(Carbon::parse('2026-11-04'), Carbon::parse('2026-10-26')))->toThrow(InvalidArgumentException::class, 'before the start date')
        ->and(fn () => DsaPeriod::of(Carbon::parse('2026-10-26'), 0))->toThrow(InvalidArgumentException::class, 'at least one day');
});

it('pays an officer for the nights of an activity from 26 October to 4 November 2026, and none for a same-day one', function () {
    $officer = Staff::factory()->create(['job_grade' => '6']);
    $long = capturedActivity(chiefOfStaff(), ['start_date' => '2026-10-26', 'days' => 10], [$officer]);
    $sameDay = capturedActivity(chiefOfStaff(), ['start_date' => '2026-12-01', 'days' => 1], [Staff::factory()->create(['job_grade' => '6'])]);

    expect($long->fresh())->nights->toBe(11)->end_date->toDateString()->toBe('2026-11-04')
        ->and($long->costs()->where('is_computed', true)->sole())->quantity->toBe('11.00')->estimated_amount->toBe('123200.00') // 11 × 11,200
        ->and($sameDay->fresh()->nights)->toBe(0)
        ->and($sameDay->costs()->where('is_computed', true)->sole()->estimated_amount)->toBe('0.00');
});

it('recalculates the departure, nights and total when the start or the end changes, without counting twice', function () {
    $cos = chiefOfStaff();
    $editor = app(ActivityEditor::class);
    $activity = capturedActivity($cos, ['start_date' => '2026-10-26', 'days' => 3], [Staff::factory()->create(['job_grade' => '6'])]);
    $dsa = fn () => $activity->costs()->where('is_computed', true)->sole();
    expect($dsa()->estimated_amount)->toBe('44800.00'); // 4 nights

    // The start moves: same length, so the same nights, from the new departure date.
    $editor->update($activity->fresh(), ['start_date' => '2026-11-02'], $cos);
    expect($activity->fresh())->end_date->toDateString()->toBe('2026-11-04')->nights->toBe(4)
        ->and(DsaPeriod::of($activity->fresh()->start_date, 3)->departure()->toDateString())->toBe('2026-11-01')
        ->and($dsa()->estimated_amount)->toBe('44800.00');

    // The end moves (two more days): 6 nights.
    $editor->update($activity->fresh(), ['days' => 5], $cos);
    expect($activity->fresh())->end_date->toDateString()->toBe('2026-11-06')->nights->toBe(6)
        ->and($dsa())->quantity->toBe('6.00')->estimated_amount->toBe('67200.00')
        ->and($activity->fresh()->estimated_total)->toBe('67200.00');

    // Recalculated again with nothing changed: still one line, the same amount.
    app(DsaCalculator::class)->recalculate($activity->fresh());
    app(DsaCalculator::class)->recalculate($activity->fresh());
    expect($activity->costs()->where('is_computed', true)->count())->toBe(1)
        ->and($dsa()->estimated_amount)->toBe('67200.00');
});

it('recomputes the total at a changed rate with the same payable nights', function () {
    $activity = capturedActivity(chiefOfStaff(), ['start_date' => '2026-10-26', 'days' => 10], [Staff::factory()->create(['job_grade' => '6'])]);
    DsaRate::query()->standard()->where('job_grade', '6')->update(['amount' => '12000.00']);

    app(DsaCalculator::class)->recalculate($activity->fresh());

    expect($activity->costs()->where('is_computed', true)->sole())->quantity->toBe('11.00')->unit_rate->toBe('12000.00')->estimated_amount->toBe('132000.00');
});

it('pays per activity day, with no departure night, on the days basis', function () {
    app(AppSettings::class)->set('dsa_basis', 'days');
    $activity = capturedActivity(chiefOfStaff(), ['start_date' => '2026-10-26', 'days' => 10], [Staff::factory()->create(['job_grade' => '6'])]);

    expect($activity->costs()->where('is_computed', true)->sole())->quantity->toBe('10.00')->estimated_amount->toBe('112000.00');
});

it('brings pending memos up to date after the change, and never touches an approved activity', function () {
    $casual = Staff::factory()->create(['staff_number' => null, 'employment_type' => EmploymentType::Casual, 'job_grade' => 'INTERN']);
    $pending = capturedActivity(chiefOfStaff(), ['start_date' => '2026-12-01', 'days' => 3], [$casual]);
    $approved = capturedActivity(chiefOfStaff(), ['start_date' => '2026-12-10', 'days' => 3], [Staff::factory()->create(['job_grade' => '6'])]);
    // As they were before the change: casual on INTERN, nights = days - 1.
    $pending->participants()->update(['job_grade' => 'INTERN']);
    $pending->costs()->where('is_computed', true)->update(['quantity' => 2, 'estimated_amount' => '8000.00', 'computed_amount' => '8000.00']);
    $approved->forceFill(['status' => ActivityStatus::Approved])->save();
    $approved->costs()->where('is_computed', true)->update(['quantity' => 2, 'estimated_amount' => '22400.00', 'computed_amount' => '22400.00']);

    $this->artisan('activities:refresh-pending-dsa', ['--dry-run' => true])->assertSuccessful();
    expect($pending->costs()->where('is_computed', true)->sole()->estimated_amount)->toBe('8000.00');

    $this->artisan('activities:refresh-pending-dsa')->assertSuccessful();

    expect($pending->participants()->sole()->job_grade)->toBe('10')
        ->and($pending->costs()->where('is_computed', true)->sole())->quantity->toBe('4.00')->estimated_amount->toBe('25200.00') // 4 nights × 6,300
        ->and($approved->costs()->where('is_computed', true)->sole()->estimated_amount)->toBe('22400.00');
});
