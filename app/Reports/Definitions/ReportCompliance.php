<?php

namespace App\Reports\Definitions;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Reports\Report;
use App\Support\FinancialYear;
use Illuminate\Support\Collection;

/**
 * Whether each completed activity's report reached the CEO within the due
 * period (Settings › Thresholds; 7 working days after the end date at the
 * business owner's request, 21 October 2026). Timed from the date the report
 * was first received; a report returned for correction keeps that date.
 */
class ReportCompliance extends Report
{
    public function key(): string
    {
        return 'report-compliance';
    }

    public function title(): string
    {
        return 'Activity Report Compliance';
    }

    public function description(): string
    {
        return 'Completed activities: when the report was due, when it came in, and whether it was on time, late or still outstanding.';
    }

    public function icon(): string
    {
        return 'fa-file-circle-check';
    }

    public function filters(): array
    {
        return ['fy', 'department'];
    }

    public function subtitle(array $filters): string
    {
        return 'FY '.$this->year($filters)->label().' · activities that ended in the year · working days after the end date';
    }

    public function columns(): array
    {
        return [
            'reference' => ['Activity', 'text'],
            'title' => ['Title', 'text'],
            'department' => ['Department', 'text'],
            'ended' => ['Ended', 'date'],
            'due' => ['Report due', 'date'],
            'received' => ['Received', 'date'],
            'timeliness' => ['On time?', 'text'],
            'days_late' => ['Working days late', 'int'],
            'review' => ['Report status', 'text'],
        ];
    }

    public function rows(array $filters): Collection
    {
        $fy = $this->year($filters);

        return Activity::query()
            ->status(ActivityStatus::Completed, ActivityStatus::ReportReceived, ActivityStatus::Closed)
            ->whereNotNull('report_due_on')
            ->whereBetween('end_date', [$fy->start()->toDateString(), $fy->end()->toDateString()])
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where('organising_department_id', $id))
            ->with(['organisingDepartment', 'report'])
            ->orderBy('report_due_on')
            ->get()
            ->map(function (Activity $a) {
                $t = $a->reportTimeliness();

                return [
                    'reference' => $a->reference,
                    'title' => $a->title,
                    'department' => $a->organisingDepartment?->name,
                    'ended' => $a->actual_end_date ?? $a->end_date,
                    'due' => $a->report_due_on,
                    'received' => $t['received_on'],
                    'timeliness' => $a->reportTimelinessLabel(),
                    'days_late' => in_array($t['status'], ['late', 'overdue'], true) ? $t['days'] : 0,
                    'review' => match (true) {
                        ! $a->report => 'Not submitted',
                        $a->report->isSubmitted() => 'Awaiting acceptance',
                        $a->report->isReturned() => 'Returned for correction',
                        default => 'Accepted',
                    },
                    '_activity_id' => $a->id,
                ];
            });
    }

    private function year(array $filters): FinancialYear
    {
        return ! empty($filters['fy']) ? FinancialYear::fromLabel($filters['fy']) : FinancialYear::current();
    }
}
