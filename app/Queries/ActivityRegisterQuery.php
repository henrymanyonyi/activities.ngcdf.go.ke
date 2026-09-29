<?php

namespace App\Queries;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Support\Period;
use Illuminate\Database\Eloquent\Builder;

/**
 * FRD SR-01 / SR-02 / SR-03: one filter definition shared by the register on
 * screen and its exports, so an export always contains exactly what is shown.
 */
final class ActivityRegisterQuery
{
    public const FILTERS = ['search', 'status', 'department_id', 'staff_id', 'region_id', 'county_id', 'activity_type_id', 'fy', 'quarter', 'from', 'to', 'flag'];

    public const SORTS = [
        'start_date' => 'start_date',
        'reference' => 'reference',
        'title' => 'title',
        'status' => 'status',
        'cost' => 'cost_total',
        'staff' => 'staff_count',
        'days' => 'days',
    ];

    public const FLAGS = [
        'late_notice' => 'Late notice',
        'retrospective' => 'Retrospective',
        'commencement_due' => 'Commencement not confirmed',
        'report_overdue' => 'Report overdue',
        'from_link' => 'Received via link',
    ];

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Activity>
     */
    public static function build(array $filters, string $sort = 'start_date', string $direction = 'desc'): Builder
    {
        $query = Activity::query()
            ->with(['organisingDepartment', 'type', 'locations.county', 'locations.region', 'locations.constituency'])
            ->search($filters['search'] ?? null);

        if (! empty($filters['status'])) {
            $status = ActivityStatus::tryFrom($filters['status']);
            $status && $query->status($status);
        }

        $query
            ->when($filters['department_id'] ?? null, fn (Builder $q, $id) => $q->where('organising_department_id', $id))
            ->when($filters['activity_type_id'] ?? null, fn (Builder $q, $id) => $q->where('activity_type_id', $id))
            ->when($filters['staff_id'] ?? null, fn (Builder $q, $id) => $q->whereHas('participants', fn (Builder $p) => $p->where('staff_id', $id)))
            ->when($filters['region_id'] ?? null, fn (Builder $q, $id) => $q->whereHas('locations', fn (Builder $l) => $l->where('region_id', $id)))
            ->when($filters['county_id'] ?? null, fn (Builder $q, $id) => $q->whereHas('locations', fn (Builder $l) => $l->where('county_id', $id)));

        if (! empty($filters['fy']) || ! empty($filters['from']) || ! empty($filters['to'])) {
            $period = Period::fromFilters($filters);
            $query->whereBetween('start_date', [$period->from->toDateString(), $period->to->toDateString()]);
        }

        match ($filters['flag'] ?? null) {
            'late_notice' => $query->where('is_late_notice', true),
            'retrospective' => $query->where('is_retrospective', true),
            'commencement_due' => $query->status(ActivityStatus::Approved)->whereDate('start_date', '<=', today()),
            'report_overdue' => $query->status(ActivityStatus::Completed)->whereDate('report_due_on', '<', today()),
            'from_link' => $query->whereNotNull('submission_link_id'),
            default => null,
        };

        $column = self::SORTS[$sort] ?? 'start_date';
        $query->orderBy($column, $direction === 'asc' ? 'asc' : 'desc')->orderByDesc('id');

        return $query;
    }
}
