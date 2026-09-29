<?php

namespace Database\Seeders;

use App\Enums\CostScope;
use App\Models\ActivityCategory;
use App\Models\CostCategory;
use App\Models\County;
use App\Models\Department;
use App\Models\Office;
use Illuminate\Database\Seeder;

/**
 * Starting lists, all maintained afterwards by the Chief of Staff in
 * Settings. Departments are the concept tracker's list (MD-02); activity
 * types are the FRD's examples (MD-03, OI-03 open). Safe to re-run.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Monitoring & Evaluation' => ['Proposal review', 'Monitoring visit', 'Project inspection'],
            'Audit & Compliance' => ['Audit', 'Investigation'],
            'Capacity Building' => ['Training', 'Sensitization', 'Benchmarking'],
            'Stakeholder Engagement' => ['Stakeholder engagement', 'Public participation'],
            'Planning & Governance' => ['Retreat', 'Board or committee meeting', 'Planning workshop'],
        ];

        foreach ($categories as $category => $types) {
            $model = ActivityCategory::firstOrCreate(['name' => $category]);
            foreach ($types as $type) {
                $model->types()->firstOrCreate(['name' => $type]);
            }
        }

        // CB-01..CB-03: DSA (computed), travel, and other costs.
        $costs = [
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

        foreach ($costs as $i => [$name, $code, $scope]) {
            CostCategory::updateOrCreate(['code' => $code], ['name' => $name, 'default_scope' => $scope, 'sort_order' => $i + 1]);
        }

        foreach ([
            'Office of the CEO / Accounting Officer', 'Finance & Accounts', 'Internal Audit & Compliance', 'Procurement', 'ICT',
            'Human Resource & Administration', 'Legal Services', 'Projects, Planning & M&E',
            'Corporate Communications', 'Regional Coordination', 'Board Secretariat',
        ] as $department) {
            Department::firstOrCreate(['name' => $department]);
        }

        Office::firstOrCreate(['name' => 'Headquarters'], ['is_headquarters' => true]);

        // OI-01 is open: a provisional destination category per county so DSA
        // can be computed; the Chief of Staff corrects these in Settings.
        $cities = ['Nairobi City', 'Mombasa', 'Kisumu', 'Nakuru', 'Uasin Gishu'];
        County::query()->whereNull('dsa_destination_category')->each(function (County $county) use ($cities) {
            $county->update(['dsa_destination_category' => in_array($county->name, $cities, true) ? 'Cities' : 'Other towns']);
        });
        // Moves the five cities off the provisional 'Other towns' default left by an earlier seed. Re-seeding
        // would also undo a deliberate 'Other towns' on one of these five; other counties are never touched.
        County::query()->whereIn('name', $cities)->where('dsa_destination_category', 'Other towns')
            ->each(fn (County $county) => $county->update(['dsa_destination_category' => 'Cities']));
    }
}
