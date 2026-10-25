<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityCost;
use App\Models\ActivityParticipant;
use App\Models\CostCategory;
use App\Models\DsaRate;
use App\Support\DsaPeriod;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * FRD CB-01 / BR-02: planned DSA per staff participant = the standard rate for
 * their job grade × payable nights (or days, per OI-01), at the rate in force on the
 * start date. The destination does not change the rate (standard rates,
 * 22 October 2026); a location in a county only shows that officers travel.
 * Payable nights follow App\Support\DsaPeriod: an overnight activity pays the
 * departure night before the start too (days + 1); a same-day activity pays none.
 * Casuals are on scale 10; attachees without a job grade are costed at the INTERN rate.
 *
 * Completed, closed, declined and cancelled activities are never recalculated,
 * so their recorded amounts stay as they were.
 *
 * Each staff participant gets at most one computed DSA line. An amount the
 * Chief of Staff has overridden is kept (with its logged reason); only the
 * computed figure beside it is refreshed.
 */
class DsaCalculator
{
    public const CATEGORY_CODE = 'dsa';

    public function __construct(private AppSettings $settings, private ActivityCosting $costing) {}

    /** DSA applies when officers travel: the activity has a location in a county. */
    public function appliesTo(Activity $activity): bool
    {
        $activity->loadMissing('locations');

        return $activity->locations->contains(fn ($l) => $l->county_id !== null);
    }

    public function units(Activity $activity, ?ActivityParticipant $participant = null): int
    {
        $days = $participant ? $participant->plannedDays($activity) : $activity->days;

        return DsaPeriod::of($participant ? $participant->startOn($activity) : $activity->start_date, max(1, $days))->units((string) $this->settings->get('dsa_basis'));
    }

    /**
     * Recompute DSA lines for every staff participant.
     *
     * @return list<string> warnings, e.g. participants with no rate
     */
    public function recalculate(Activity $activity): array
    {
        $category = CostCategory::query()->where('code', self::CATEGORY_CODE)->first();
        if (! $category) {
            return ['The DSA cost category is missing from Settings.'];
        }

        if ($activity->status->isDelivered() || $activity->status->isReadOnly()) {
            return ['DSA is not recalculated once an activity is completed, closed, declined or cancelled; its recorded amounts are kept.'];
        }

        $travels = $this->appliesTo($activity);
        $warnings = [];

        if (! $travels) {
            $warnings[] = 'No location in a county is set for the activity, so DSA was not calculated.';
        }

        DB::transaction(function () use ($activity, $category, $travels, &$warnings) {
            $activity->load('participants.staff');

            foreach ($activity->participants->where('is_external', false) as $participant) {
                $line = ActivityCost::query()
                    ->where('activity_id', $activity->id)
                    ->where('activity_participant_id', $participant->id)
                    ->where('cost_category_id', $category->id)
                    ->where('is_computed', true)
                    ->first();

                $grade = DsaRate::gradeFor($participant->job_grade ?? $participant->staff?->job_grade, $participant->staff?->employment_type);
                $rate = $travels ? DsaRate::lookup($grade, $activity->start_date) : null;

                if (! $rate) {
                    if ($travels) {
                        $warnings[] = sprintf('No DSA rate for %s (job grade %s).', $participant->displayName(), $grade ?: 'not set');
                    }

                    continue;
                }

                $units = $this->units($activity, $participant);
                // A group added by number (e.g. 10 FAMs) is paid for each of them.
                $people = $participant->people();
                $computed = Money::fromCents(Money::toCents($rate->amount) * $units * $people);
                $description = sprintf('DSA %d %s × %s', $units, $this->settings->get('dsa_basis') === 'days' ? 'days' : 'nights', Money::format(Money::toCents($rate->amount)))
                    .($participant->isGroup() ? " × {$people} people" : '');

                if (! $line) {
                    $activity->costs()->create([
                        'cost_category_id' => $category->id,
                        'activity_participant_id' => $participant->id,
                        'description' => $description,
                        'quantity' => $units,
                        'unit_rate' => $rate->amount,
                        'estimated_amount' => $computed,
                        'is_computed' => true,
                        'dsa_rate_id' => $rate->id,
                        'computed_amount' => $computed,
                        'recorded_by' => Auth::id(),
                    ]);

                    continue;
                }

                $wasOverridden = $line->isOverridden();
                // The description follows the nights too, so it never shows the count from when the line was first made.
                $line->fill([
                    'description' => $description,
                    'quantity' => $units,
                    'unit_rate' => $rate->amount,
                    'dsa_rate_id' => $rate->id,
                    'computed_amount' => $computed,
                ]);
                if (! $wasOverridden) {
                    $line->estimated_amount = $computed;
                }
                $line->save();
            }

            $this->costing->refresh($activity);
        });

        return $warnings;
    }

    /** CB-01: override a computed amount with a mandatory, logged justification. */
    public function override(ActivityCost $line, string $amount, string $reason): void
    {
        abort_unless($line->is_computed, 422, 'Only a computed DSA amount can be overridden.');
        abort_if(trim($reason) === '', 422, 'A justification is required to override a computed amount.');

        $line->update([
            'estimated_amount' => Money::fromCents(Money::toCents($amount)),
            'override_reason' => trim($reason),
        ]);

        $this->costing->refresh($line->loadMissing('activity')->activity);
    }
}
