<?php

use App\Models\ActivityCategory;
use App\Models\AppSetting;
use App\Models\CostCategory;
use App\Models\County;
use App\Models\Department;
use App\Models\DsaRate;
use App\Models\Office;
use App\Services\AppSettings;
use Database\Seeders\DatabaseSeeder;

it('sets up every configuration a new installation needs', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Department::count())->toBe(11)
        ->and(Office::where('is_headquarters', true)->count())->toBe(1)
        ->and(Office::whereNotNull('region_id')->count())->toBe(10)
        ->and(ActivityCategory::count())->toBe(5)
        ->and(CostCategory::where('code', 'dsa')->exists())->toBeTrue()
        ->and(County::whereNull('dsa_destination_category')->count())->toBe(0)
        ->and(County::where('name', 'Nairobi City')->value('dsa_destination_category'))->toBe('Cities')
        ->and(AppSetting::whereIn('key', array_keys(AppSettings::DEFINITIONS))->count())->toBe(count(AppSettings::DEFINITIONS))
        ->and(DsaRate::where('is_provisional', true)->count())->toBe(16);
});

it('never undoes changes made in Settings when run again', function () {
    $this->seed(DatabaseSeeder::class);

    Department::where('name', 'ICT')->update(['name' => 'ICT Department']);
    Department::where('name', 'Legal Services')->update(['is_active' => false]);
    CostCategory::where('code', 'meals')->update(['name' => 'Catering', 'is_active' => false]);
    County::where('name', 'Nairobi City')->update(['dsa_destination_category' => 'Other towns']);
    app(AppSettings::class)->set('late_notice_working_days', 10);
    DsaRate::query()->delete();

    $this->seed(DatabaseSeeder::class);

    expect(Department::where('name', 'ICT')->exists())->toBeFalse()
        ->and(Department::where('name', 'Legal Services')->value('is_active'))->toBeFalse()
        ->and(CostCategory::where('code', 'meals')->first()->only(['name', 'is_active']))->toBe(['name' => 'Catering', 'is_active' => false])
        ->and(County::where('name', 'Nairobi City')->value('dsa_destination_category'))->toBe('Other towns')
        ->and(app(AppSettings::class)->int('late_notice_working_days'))->toBe(10)
        ->and(DsaRate::count())->toBe(0);
});

it('does not add provisional DSA rates where rates were already entered', function () {
    DsaRate::create(['job_grade' => 'NGCDF 5', 'destination_category' => 'Cities', 'amount' => '9000', 'effective_from' => '2026-07-01']);

    $this->seed(DatabaseSeeder::class);

    expect(DsaRate::count())->toBe(1)->and(DsaRate::where('is_provisional', true)->exists())->toBeFalse();
});
