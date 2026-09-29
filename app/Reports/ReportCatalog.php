<?php

namespace App\Reports;

use Illuminate\Support\Collection;

final class ReportCatalog
{
    /** @var list<class-string<Report>> */
    private const REPORTS = [
        Definitions\FieldActivityPlan::class,
        Definitions\WeeklyFieldSchedule::class,
        Definitions\DecisionsRegister::class,
        Definitions\PlannedAgainstActual::class,
        Definitions\OfficerFieldDays::class,
        Definitions\DepartmentFieldExpenditure::class,
        Definitions\OverdueReportsAndDirectives::class,
        Definitions\Coverage::class,
        Definitions\ActivityRegister::class,
        Definitions\AccessAndAuditLog::class,
    ];

    /** @return Collection<string, Report> */
    public static function all(): Collection
    {
        return collect(self::REPORTS)->map(fn (string $class) => app($class))->keyBy(fn (Report $r) => $r->key());
    }

    public static function find(string $key): ?Report
    {
        return self::all()->get($key);
    }
}
