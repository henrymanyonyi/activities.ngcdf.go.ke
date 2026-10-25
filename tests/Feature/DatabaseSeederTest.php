<?php

use App\Models\ActivityCategory;
use App\Models\AppSetting;
use App\Models\CostCategory;
use App\Models\Department;
use App\Models\DsaRate;
use App\Models\Office;
use App\Models\Staff;
use App\Services\AppSettings;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReferenceDataSeeder;

it('sets up every configuration a new installation needs', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Department::count())->toBe(16)
        ->and(Department::pluck('name')->sort()->values()->all())->toBe(collect(ReferenceDataSeeder::DEPARTMENTS)->sort()->values()->all())
        ->and(Office::where('is_headquarters', true)->count())->toBe(1)
        ->and(Office::whereNotNull('region_id')->count())->toBe(10)
        ->and(ActivityCategory::count())->toBe(5)
        ->and(CostCategory::where('code', 'dsa')->exists())->toBeTrue()
        ->and(AppSetting::whereIn('key', array_keys(AppSettings::DEFINITIONS))->count())->toBe(count(AppSettings::DEFINITIONS))
        ->and(DsaRate::standard()->pluck('amount', 'job_grade')->all())->toBe(DsaRate::STANDARD)
        ->and(DsaRate::whereNotNull('destination_category')->exists())->toBeFalse();
});

it('never undoes changes made in Settings when run again', function () {
    $this->seed(DatabaseSeeder::class);

    Department::where('name', 'ICT')->update(['name' => 'ICT Department']);
    Department::where('name', 'Legal Services')->update(['is_active' => false]);
    CostCategory::where('code', 'meals')->update(['name' => 'Catering', 'is_active' => false]);
    app(AppSettings::class)->set('late_notice_working_days', 10);

    $this->seed(DatabaseSeeder::class);

    expect(Department::where('name', 'ICT')->exists())->toBeFalse()
        ->and(Department::where('name', 'Legal Services')->value('is_active'))->toBeFalse()
        ->and(CostCategory::where('code', 'meals')->first()->only(['name', 'is_active']))->toBe(['name' => 'Catering', 'is_active' => false])
        ->and(app(AppSettings::class)->int('late_notice_working_days'))->toBe(10);
});

it('brings an installation seeded with the concept list up to the 16 departments without duplicates or losses', function () {
    // As installed before 11 October 2026: the concept list, and the marker that it was seeded.
    AppSetting::where('key', 'seeded.departments_board_structure')->delete();
    Department::query()->delete();
    $legacy = collect(['Office of the CEO / Accounting Officer', 'Finance & Accounts', 'Procurement', 'ICT', 'Human Resource & Administration'])
        ->mapWithKeys(fn ($name) => [$name => Department::create(['name' => $name])->id]);
    $staff = Staff::factory()->create(['department_id' => $legacy['Finance & Accounts']]);

    $this->seed(ReferenceDataSeeder::class);

    expect(Department::find($legacy['Finance & Accounts'])->name)->toBe('Financial Services - Accounts')
        ->and(Department::find($legacy['Office of the CEO / Accounting Officer'])->name)->toBe('Office of the CEO')
        ->and(Department::find($legacy['Procurement'])->name)->toBe('Supply Chain')
        ->and($staff->fresh()->department_id)->toBe($legacy['Finance & Accounts'])
        // A merged unit is kept for the Chief of Staff to merge in Settings.
        ->and(Department::where('name', 'Human Resource & Administration')->exists())->toBeTrue()
        ->and(Department::whereRaw('LOWER(name) = ?', ['ict'])->count())->toBe(1)
        ->and(Department::whereIn('name', ReferenceDataSeeder::DEPARTMENTS)->count())->toBe(16)
        ->and(Department::count())->toBe(17);
});
