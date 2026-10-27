<?php

namespace App\Console\Commands;

use App\Services\StaffChanges;
use Illuminate\Console\Command;

/** Daily: applies the promotions, demotions and changes of access scheduled for today or earlier (App\Services\StaffChanges). */
class ApplyScheduledStaffChanges extends Command
{
    protected $signature = 'activities:apply-staff-changes';

    protected $description = 'Apply the staff position and access changes whose effective date has come';

    public function handle(StaffChanges $changes): int
    {
        $counts = $changes->applyDue();

        $this->info("{$counts['applied']} applied, {$counts['failed']} could not be applied.");

        return self::SUCCESS;
    }
}
