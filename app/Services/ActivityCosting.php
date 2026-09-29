<?php

namespace App\Services;

use App\Enums\ParticipationStatus;
use App\Models\Activity;
use App\Models\ActivityCost;
use App\Models\ActivityParticipant;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Every cost and headcount figure for an activity comes from here, in integer
 * cents (BR-09).
 *
 * Headcount: until the activity is delivered, everyone still expected
 * (nominated, confirmed, attended); once Completed or later, only those
 * confirmed as attended (BR-07). External participants count toward cost per
 * head only when the activity's count_externals_in_per_head switch is on.
 *
 * Planned total = Σ planned; actual total = Σ actual where recorded; the
 * best-known total uses the actual where recorded and the planned amount
 * otherwise. Total = DSA + travel + other (BR-03).
 *
 * Cost attributable to a person = their own lines + an equal share of the
 * shared (activity-level) lines across counted heads, reported separately.
 */
class ActivityCosting
{
    /** Recompute and store the totals on the activity. Call after any participant or cost change. */
    public function refresh(Activity $activity): Activity
    {
        $participants = $activity->participants()->get(['id', 'is_external', 'status']);
        $counted = $participants->filter(fn (ActivityParticipant $p) => $this->isCounted($activity, $p));

        $costs = $activity->costs()->get(['estimated_amount', 'actual_amount']);

        $planned = $costs->sum(fn (ActivityCost $c) => Money::toCents($c->estimated_amount));
        $actual = $costs->whereNotNull('actual_amount')->sum(fn (ActivityCost $c) => Money::toCents($c->actual_amount));
        $best = $costs->sum(fn (ActivityCost $c) => $this->bestCents($c));

        $activity->forceFill([
            'staff_count' => $counted->where('is_external', false)->count(),
            'external_count' => $counted->where('is_external', true)->count(),
            'estimated_total' => Money::fromCents($planned),
            'actual_total' => Money::fromCents($actual),
            'cost_total' => Money::fromCents($best),
            'actuals_complete' => $costs->isNotEmpty() && $costs->every(fn (ActivityCost $c) => $c->actual_amount !== null),
        ])->saveQuietly();

        return $activity;
    }

    public function isCounted(Activity $activity, ActivityParticipant $participant): bool
    {
        if ($activity->status->isDelivered()) {
            return $participant->status === ParticipationStatus::Attended;
        }

        return $participant->status->isExpected();
    }

    /** Counts toward cost per head (externals only when the activity says so). */
    public function countsPerHead(Activity $activity, ActivityParticipant $participant): bool
    {
        return $this->isCounted($activity, $participant)
            && (! $participant->is_external || $activity->count_externals_in_per_head);
    }

    public function bestCents(ActivityCost $cost): int
    {
        return Money::toCents($cost->actual_amount ?? $cost->estimated_amount);
    }

    /**
     * Per-participant breakdown for the activity page.
     *
     * @return array{shared: int, heads: int, share: int, staff_per_head: int|null, all_per_head: int|null, rows: Collection<int, array{participant: ActivityParticipant, planned: int, actual: int|null, direct: int, share: int, total: int}>}
     */
    public function breakdown(Activity $activity): array
    {
        $activity->loadMissing(['participants.staff', 'participants.department', 'costs']);

        $shared = $activity->costs->whereNull('activity_participant_id')->sum(fn (ActivityCost $c) => $this->bestCents($c));
        $heads = $activity->participants->filter(fn (ActivityParticipant $p) => $this->countsPerHead($activity, $p))->count();
        $share = Money::divide($shared, $heads);

        $lines = $activity->costs->whereNotNull('activity_participant_id')->groupBy('activity_participant_id');

        $rows = $activity->participants->map(function (ActivityParticipant $p) use ($activity, $lines, $share) {
            /** @var Collection<int, ActivityCost> $own */
            $own = $lines->get($p->id, collect());
            $direct = $own->sum(fn (ActivityCost $c) => $this->bestCents($c));
            $allocated = $this->countsPerHead($activity, $p) ? $share : 0;

            return [
                'participant' => $p,
                'planned' => $own->sum(fn (ActivityCost $c) => Money::toCents($c->estimated_amount)),
                'actual' => $own->whereNotNull('actual_amount')->isEmpty() ? null : $own->whereNotNull('actual_amount')->sum(fn (ActivityCost $c) => Money::toCents($c->actual_amount)),
                'direct' => $direct,
                'share' => $allocated,
                'total' => $direct + $allocated,
            ];
        });

        $total = Money::toCents($activity->cost_total);
        $allHeads = $activity->staff_count + $activity->external_count;

        return [
            'shared' => $shared,
            'heads' => $heads,
            'share' => $share,
            'staff_per_head' => $activity->staff_count > 0 ? Money::divide($total, $activity->staff_count) : null,
            'all_per_head' => $allHeads > 0 ? Money::divide($total, $allHeads) : null,
            'rows' => $rows,
        ];
    }

    /**
     * Totals per cost category, planned vs actual.
     *
     * @return Collection<int, array{category: string, planned: int, actual: int, best: int}>
     */
    public function byCategory(Activity $activity): Collection
    {
        $activity->loadMissing('costs.category');

        return $activity->costs
            ->groupBy('cost_category_id')
            ->map(fn (Collection $lines) => [
                'category' => (string) $lines->first()->category?->name,
                'planned' => $lines->sum(fn (ActivityCost $c) => Money::toCents($c->estimated_amount)),
                'actual' => $lines->whereNotNull('actual_amount')->sum(fn (ActivityCost $c) => Money::toCents($c->actual_amount)),
                'best' => $lines->sum(fn (ActivityCost $c) => $this->bestCents($c)),
            ])
            ->sortByDesc('best')
            ->values();
    }
}
