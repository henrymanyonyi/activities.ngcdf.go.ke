<?php

use App\Support\FinancialYear;
use App\Support\Money;
use App\Support\WorkingDays;
use Illuminate\Support\Carbon;

it('handles money as exact cents', function () {
    expect(Money::toCents('1,234.50'))->toBe(123450)
        ->and(Money::toCents('0.1'))->toBe(10)
        ->and(Money::toCents(7))->toBe(700)
        ->and(Money::fromCents(123450))->toBe('1234.50')
        ->and(Money::format(123456789))->toBe('1,234,567.89')
        ->and(Money::divide(10000, 3))->toBe(3333)
        ->and(Money::toCents('0.10') + Money::toCents('0.20'))->toBe(30);
});

it('rejects amounts with more than two decimals', function () {
    Money::toCents('1.005');
})->throws(InvalidArgumentException::class);

it('computes the Kenyan financial year and quarters', function () {
    $fy = FinancialYear::for(Carbon::parse('2026-09-29'));

    expect($fy->label())->toBe('2026/27')
        ->and(FinancialYear::for(Carbon::parse('2027-06-30'))->label())->toBe('2026/27')
        ->and(FinancialYear::quarterOf(Carbon::parse('2026-09-29')))->toBe(1)
        ->and(FinancialYear::quarterOf(Carbon::parse('2027-02-01')))->toBe(3)
        ->and($fy->quarter(2)[0]->toDateString())->toBe('2026-10-01');
});

it('counts working days', function () {
    // Friday + 1 working day = Monday
    expect(WorkingDays::add(Carbon::parse('2026-10-02'), 1)->toDateString())->toBe('2026-10-05')
        ->and(WorkingDays::between(Carbon::parse('2026-10-02'), Carbon::parse('2026-10-09')))->toBe(5);
});
