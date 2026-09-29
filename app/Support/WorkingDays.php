<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Monday–Friday arithmetic for the late-notice (OI-04) and report-due (OI-07)
 * periods. Public holidays are not excluded; the periods are indicative flags.
 */
final class WorkingDays
{
    public static function add(Carbon $from, int $days): Carbon
    {
        $date = $from->copy()->startOfDay();
        while ($days > 0) {
            $date->addDay();
            if (! $date->isWeekend()) {
                $days--;
            }
        }

        return $date;
    }

    /** Working days strictly after $from up to and including $to. */
    public static function between(Carbon $from, Carbon $to): int
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        if ($to->lte($from)) {
            return 0;
        }

        $count = 0;
        for ($d = $from->copy()->addDay(); $d->lte($to); $d->addDay()) {
            if (! $d->isWeekend()) {
                $count++;
            }
        }

        return $count;
    }
}
