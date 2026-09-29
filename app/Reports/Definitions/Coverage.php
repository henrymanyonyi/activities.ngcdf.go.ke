<?php

namespace App\Reports\Definitions;

use App\Reports\Report;
use App\Services\Analytics;
use App\Support\Period;
use Illuminate\Support\Collection;

class Coverage extends Report
{
    public function key(): string
    {
        return 'coverage';
    }

    public function title(): string
    {
        return 'Coverage';
    }

    public function description(): string
    {
        return 'Regions, counties and constituencies visited and not visited.';
    }

    public function icon(): string
    {
        return 'fa-map';
    }

    public function columns(): array
    {
        return [
            'level' => ['Level', 'text'],
            'name' => ['Name', 'text'],
            'county' => ['County', 'text'],
            'visited' => ['Visited', 'text'],
        ];
    }

    public function rows(array $filters): Collection
    {
        $coverage = app(Analytics::class)->coverage(Period::fromFilters($filters));

        return collect()
            ->concat($coverage['regions']->map(fn ($r) => ['level' => 'Region', 'name' => $r['name'], 'county' => null, 'visited' => $r['visited'] ? 'Yes' : 'No']))
            ->concat($coverage['counties']->map(fn ($r) => ['level' => 'County', 'name' => $r['name'], 'county' => null, 'visited' => $r['visited'] ? 'Yes' : 'No']))
            ->concat($coverage['constituencies']->map(fn ($r) => ['level' => 'Constituency', 'name' => $r['name'], 'county' => $r['county'], 'visited' => $r['visited'] ? 'Yes' : 'No']))
            ->values();
    }
}
