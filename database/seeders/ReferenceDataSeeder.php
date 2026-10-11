<?php

namespace Database\Seeders;

use App\Enums\CostScope;
use App\Models\ActivityCategory;
use App\Models\CostCategory;
use App\Models\County;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Office;
use App\Models\Region;
use Database\Seeders\Concerns\SeedsOnce;
use Illuminate\Database\Seeder;

/**
 * Starting reference lists, maintained afterwards by the Chief of Staff in
 * Settings › Reference data. Each list is seeded once per installation (see
 * SeedsOnce), so re-running never undoes an edit. Cost types are matched by
 * their fixed code on every run, so a new type added in a later release
 * appears without touching names or states set in Settings.
 */
class ReferenceDataSeeder extends Seeder
{
    use SeedsOnce;

    /** The Board's 16 departments (FRD MD-02; list supplied 11 October 2026, replacing the concept tracker's 11). */
    public const DEPARTMENTS = [
        'Human Resources', 'Internal Audit', 'Corporate Communications', 'Office of the CEO', 'Financial Services - Accounts',
        'Field Financial Coordination', 'ICT', 'Research & Statistics', 'Monitoring & Evaluation', 'Risk & Compliance',
        'Supply Chain', 'Programmes', 'Corporate, Planning & Strategy', 'Administration', 'Legal Services',
        'Special Initiatives & Partnerships',
    ];

    /**
     * Concept-list names that are the same unit under a new name. Merged units
     * (e.g. Human Resource & Administration) are not here: their staff must be
     * moved by the Chief of Staff with Settings › Departments › Merge.
     */
    public const RENAMED_DEPARTMENTS = [
        'Office of the CEO / Accounting Officer' => 'Office of the CEO',
        'Finance & Accounts' => 'Financial Services - Accounts',
        'Procurement' => 'Supply Chain',
    ];

    public const DESIGNATIONS = [
        'Chief Executive Officer', 'Director', 'Deputy Director', 'Manager', 'Principal Officer', 'Senior Officer', 'Officer',
        'Assistant Officer', 'Regional Coordinator', 'Accountant', 'Internal Auditor', 'M&E Officer', 'Legal Officer',
        'ICT Officer', 'Procurement Officer', 'Communications Officer', 'Secretary', 'Driver',
    ];

    /** FRD MD-03 examples; OI-03 is open. */
    public const ACTIVITY_TYPES = [
        'Monitoring & Evaluation' => ['Proposal review', 'Monitoring visit', 'Project inspection'],
        'Audit & Compliance' => ['Audit', 'Investigation'],
        'Capacity Building' => ['Training', 'Sensitization', 'Benchmarking'],
        'Stakeholder Engagement' => ['Stakeholder engagement', 'Public participation'],
        'Planning & Governance' => ['Retreat', 'Board or committee meeting', 'Planning workshop'],
    ];

    /**
     * FRD CB-01..CB-03. Codes are fixed: `dsa` is computed from the rate
     * table, and dsa / travel / fuel / vehicle_hire drive the DSA and travel
     * columns (CostCategory::group()).
     *
     * @var list<array{0: string, 1: string, 2: CostScope}>
     */
    public const COST_TYPES = [
        ['DSA', 'dsa', CostScope::Participant],
        ['Travel', 'travel', CostScope::Participant],
        ['Board vehicle fuel', 'fuel', CostScope::Activity],
        ['Vehicle hire', 'vehicle_hire', CostScope::Activity],
        ['Accommodation', 'accommodation', CostScope::Participant],
        ['Venue / conference package', 'venue', CostScope::Activity],
        ['Meals', 'meals', CostScope::Activity],
        ['Training materials', 'materials', CostScope::Activity],
        ['Airtime / communication', 'airtime', CostScope::Participant],
        ['Incidentals', 'incidentals', CostScope::Activity],
        ['Other approved costs', 'other', CostScope::Activity],
    ];

    /** Provisional DSA destination categories (OI-01): these five are Cities, every other county Other towns. */
    public const CITY_COUNTIES = ['Nairobi City', 'Mombasa', 'Kisumu', 'Nakuru', 'Uasin Gishu'];

    public function run(): void
    {
        foreach (self::COST_TYPES as $i => [$name, $code, $scope]) {
            CostCategory::query()->firstOrCreate(['code' => $code], ['name' => $name, 'default_scope' => $scope, 'sort_order' => $i + 1]);
        }

        $this->once('departments', function () {
            foreach (self::DEPARTMENTS as $name) {
                Department::resolveByName($name);
            }
        });

        // Installations seeded with the concept list: rename the units that only
        // changed name (keeping their id, so staff and history stay attached),
        // then add any of the 16 still missing. Nothing is removed.
        $this->once('departments_board_structure', function () {
            foreach (self::RENAMED_DEPARTMENTS as $old => $new) {
                $legacy = Department::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($old)])->first();
                if ($legacy && Department::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($new)])->doesntExist()) {
                    $legacy->update(['name' => $new]);
                }
            }

            foreach (self::DEPARTMENTS as $name) {
                Department::resolveByName($name);
            }
        });

        $this->once('designations', function () {
            foreach (self::DESIGNATIONS as $name) {
                Designation::resolveByName($name);
            }
        });

        // Headquarters plus one duty station per region, named as the region
        // so an HR list that says "Western Region" matches it.
        $this->once('duty_stations', function () {
            Office::resolveByName('Headquarters', ['is_headquarters' => true])?->update(['is_headquarters' => true]);

            Region::query()->orderBy('name')->each(function (Region $region) {
                $office = Office::resolveByName($region->name, ['region_id' => $region->id]);
                if ($office && ! $office->region_id) {
                    $office->update(['region_id' => $region->id]);
                }
            });
        });

        $this->once('activity_types', function () {
            foreach (self::ACTIVITY_TYPES as $category => $types) {
                $model = ActivityCategory::resolveByName($category);
                foreach ($types as $type) {
                    $model->types()->firstOrCreate(['name' => $type]);
                }
            }
        });

        // Needs the counties from GeographySeeder. Only fills counties that have no category yet.
        $this->once('dsa_destinations', function () {
            County::query()->whereNull('dsa_destination_category')->each(function (County $county) {
                $county->update(['dsa_destination_category' => in_array($county->name, self::CITY_COUNTIES, true) ? 'Cities' : 'Other towns']);
            });
        });
    }
}
