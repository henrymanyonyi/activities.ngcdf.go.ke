<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityCost;
use App\Models\ActivityParticipant;
use App\Models\CostCategory;
use App\Models\DsaRate;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * FRD CB-01 / BR-02: planned DSA per staff participant = rate for their job
 * grade and the destination category × nights (or days, per OI-01), at the
 * rate in force on the start date.
 *
 * Each staff participant gets at most one computed DSA line. An amount the
 * Chief of Staff has overridden is kept (with its logged reason); only the
 * computed figure beside it is refreshed.
 */
class DsaCalculator
{
    public const CATEGORY_CODE = 'dsa';

    public function __construct(private AppSettings $settings, private ActivityCosting $costing) {}

    /** The destination category of the activity: the first location whose county has one. */
    public function destinationCategory(Activity $activity): ?string
    {
        $activity->loadMissing('locations.county');

        return $activity->locations->map(fn ($l) => $l->county?->dsa_destination_category)->filter()->first();
    }

    public function units(Activity $activity, ?ActivityParticipant $participant = null): int
    {
        $days = $participant ? $participant->plannedDays($activity) : $activity->days;

        return $this->settings->get('dsa_basis') === 'days' ? $days : max(0, $days - 1);
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

        $destination = $this->destinationCategory($activity);
        $warnings = [];

        if ($destination === null) {
            $warnings[] = 'No DSA destination category is set for the activity location, so DSA was not calculated.';
        }

        DB::transaction(function () use ($activity, $category, $destination, &$warnings) {
            $activity->load('participants.staff');

            foreach ($activity->participants->where('is_external', false) as $participant) {
                $line = ActivityCost::query()
                    ->where('activity_id', $activity->id)
                    ->where('activity_participant_id', $participant->id)
                    ->where('cost_category_id', $category->id)
                    ->where('is_computed', true)
                    ->first();

                $rate = $destination ? DsaRate::lookup($participant->job_grade ?? $participant->staff?->job_grade, $destination, $activity->start_date) : null;

                if (! $rate) {
                    if ($destination) {
                        $warnings[] = sprintf('No DSA rate for %s (job grade %s, %s).', $participant->displayName(), $participant->job_grade ?: 'not set', $destination);
                    }

                    continue;
                }

                $units = $this->units($activity, $participant);
                $computed = Money::fromCents(Money::toCents($rate->amount) * $units);

                if (! $line) {
                    $activity->costs()->create([
                        'cost_category_id' => $category->id,
                        'activity_participant_id' => $participant->id,
                        'description' => sprintf('DSA %d %s × %s', $units, $this->settings->get('dsa_basis') === 'days' ? 'days' : 'nights', Money::format(Money::toCents($rate->amount))),
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
                $line->fill([
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
