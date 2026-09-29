<?php

namespace Database\Seeders;

use App\Models\Constituency;
use App\Models\County;
use App\Models\Region;
use Illuminate\Database\Seeder;

/**
 * FRD MD-04: the ten regions, 47 counties and 290 constituencies, from a
 * snapshot of Smart NG-CDF's geography (database/data/geography.json). There
 * is no live link to Smart (FRD 12). Safe to re-run; updates names in place.
 */
class GeographySeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode((string) file_get_contents(database_path('data/geography.json')), true, flags: JSON_THROW_ON_ERROR);

        $regions = [];
        foreach ($data['regions'] as $row) {
            $regions[$row['code']] = Region::updateOrCreate(
                ['code' => $row['code']],
                ['name' => $row['name'], 'smart_cluster_id' => $row['smart_cluster_id']],
            )->id;
        }

        $counties = [];
        foreach ($data['counties'] as $row) {
            $counties[$row['code']] = County::updateOrCreate(['code' => $row['code']], ['name' => $row['name']])->id;
        }

        foreach ($data['constituencies'] as $row) {
            Constituency::updateOrCreate(['code' => $row['code']], [
                'name' => $row['name'],
                'county_id' => $counties[$row['county_code']],
                'region_id' => $regions[$row['region_code']],
            ]);
        }
    }
}
