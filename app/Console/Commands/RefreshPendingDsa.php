<?php

namespace App\Console\Commands;

use App\Enums\ActivityStatus;
use App\Enums\EmploymentType;
use App\Models\Activity;
use App\Models\ActivityParticipant;
use App\Services\AuditLogger;
use App\Services\DsaCalculator;
use App\Support\DsaPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * After the DSA changes of 25 October 2026 (payable nights include the departure
 * night; every casual on scale 10), brings memos that are still being prepared
 * or reviewed up to date: casual officers on their teams move to scale 10 and
 * their DSA is recalculated. Approved, running, completed, closed, declined and
 * cancelled activities are never touched: what the CEO approved stays as approved.
 * Run once after the production update, with --dry-run first.
 */
class RefreshPendingDsa extends Command
{
    protected $signature = 'activities:refresh-pending-dsa {--dry-run : List what would change, change nothing}';

    protected $description = 'Recalculate DSA on memos not yet approved (casuals on scale 10, departure night included)';

    /** Not yet approved: still with the department, a reviewer or the CEO. */
    private const PENDING = [
        ActivityStatus::Draft, ActivityStatus::WithHod, ActivityStatus::ReturnedToPreparer, ActivityStatus::UnderReview,
        ActivityStatus::ReturnedToHod, ActivityStatus::AwaitingDecision, ActivityStatus::Returned,
    ];

    public function handle(DsaCalculator $dsa): int
    {
        $memos = Activity::query()->status(...self::PENDING)->with('participants.staff')->orderBy('id')->get();
        $dry = (bool) $this->option('dry-run');
        $rows = [];

        foreach ($memos as $memo) {
            $casuals = $memo->participants->filter(fn (ActivityParticipant $p) => $p->staff?->employment_type === EmploymentType::Casual && $p->job_grade !== EmploymentType::CASUAL_SCALE);
            $before = $memo->estimated_total;

            if (! $dry) {
                DB::transaction(function () use ($memo, $casuals, $dsa) {
                    AuditLogger::actingAs('DSA update 25 October 2026', function () use ($memo, $casuals, $dsa) {
                        $casuals->each(fn (ActivityParticipant $p) => $p->update(['job_grade' => EmploymentType::CASUAL_SCALE]));
                        $memo->forceFill(['nights' => DsaPeriod::nightsFor($memo->days)])->save();
                        $dsa->recalculate($memo);
                    });
                });
            }

            $rows[] = [$memo->reference, $memo->status->label(), $casuals->count(), $before, $dry ? '(dry run)' : $memo->fresh()->estimated_total];
        }

        $this->table(['Memo', 'Status', 'Casuals moved to scale 10', 'Total before', 'Total after'], $rows);
        $this->info(count($rows).' pending memos '.($dry ? 'would be' : 'were').' recalculated. Approved and later activities were not touched.');

        return self::SUCCESS;
    }
}
