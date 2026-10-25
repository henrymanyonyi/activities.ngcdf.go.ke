<?php

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Enums\ParticipantRole;
use App\Exceptions\ActivityWorkflowException;
use App\Models\Activity;
use App\Models\ActivityAmendment;
use App\Models\ActivityCost;
use App\Models\ActivityParticipant;
use App\Models\CostCategory;
use App\Models\ParticipantRelease;
use App\Models\User;
use App\Support\DsaPeriod;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recall, release or reassignment of an officer part-way through an approved
 * or in-progress activity (business owner's rules, 21 October 2026):
 *
 *  - Recorded by the Chief of Staff, the Assistant or the CEO
 *    (activities.execute); a Head of Department cannot.
 *  - The officer stays on the activity up to and including their last day and
 *    is available to any other activity from the next day. The original
 *    assignment is kept on the release record; nothing is deleted.
 *  - Only computed DSA is reduced automatically (rate × the nights they now
 *    spend). Travel, accommodation and every other line remains payable unless
 *    the recorder enters a revised amount with a reason; an extra cost the
 *    change causes (a return ticket) can be added.
 *  - The effect is shown before it is confirmed, and recorded as an amendment
 *    against the approved total. A rise beyond the cost tolerance returns the
 *    activity to the CEO (BR-10). A reduction is a projected saving, never
 *    treated as money returned.
 */
class ParticipantReleases
{
    public function __construct(
        private ActivityLifecycle $lifecycle,
        private ActivityEditor $editor,
        private ActivityCosting $costing,
        private DsaCalculator $dsa,
        private AppSettings $settings,
    ) {}

    /** Statuses in which an officer can be released. */
    public static function canRelease(Activity $activity): bool
    {
        return in_array($activity->status, [ActivityStatus::Approved, ActivityStatus::InProgress], true);
    }

    /**
     * The cost effect of releasing the officer after the given last day.
     *
     * @param  array<int, string|null>  $revised  cost line id => revised planned amount ('' or null: unchanged)
     * @param  array{amount?: ?string, cost_category_id?: ?int, description?: ?string}  $extra
     * @return array{lines: list<array{id: ?int, category: string, description: string, before: int, after: int, treatment: string}>, before: int, after: int, change: int, activity_before: int, activity_after: int, approved: ?int, days_before: int, days_after: int, available_from: Carbon, recovery: int}
     */
    public function preview(ActivityParticipant $participant, Carbon $lastDay, array $revised = [], array $extra = []): array
    {
        $activity = $participant->loadMissing('activity')->activity;
        $participant->loadMissing('costs.category');
        $this->assertDates($participant, $activity, $lastDay);

        $start = $participant->startOn($activity);
        $daysAfter = (int) $start->diffInDays($lastDay) + 1;
        $units = DsaPeriod::of($start, max(1, $daysAfter))->units((string) $this->settings->get('dsa_basis'));

        $lines = $participant->costs->map(function (ActivityCost $line) use ($revised, $units) {
            $before = Money::toCents($line->estimated_amount);
            // DSA follows the rule; only the other lines take a typed amount.
            $typed = $line->is_computed ? null : ($revised[$line->id] ?? null);

            [$after, $treatment] = match (true) {
                filled($typed) => [Money::toCents((string) $typed), ParticipantRelease::LINE_REVISED],
                $line->is_computed && ! $line->isOverridden() && $line->unit_rate !== null => [Money::toCents($line->unit_rate) * $units, ParticipantRelease::LINE_REDUCED],
                default => [$before, ParticipantRelease::LINE_PAYABLE],
            };

            return [
                'id' => $line->id,
                'category' => (string) $line->category?->name,
                'description' => (string) $line->description,
                'before' => $before,
                'after' => $after,
                'treatment' => $treatment === ParticipantRelease::LINE_REDUCED && $after === $before ? ParticipantRelease::LINE_PAYABLE : $treatment,
            ];
        })->values()->all();

        if (filled($extra['amount'] ?? null)) {
            $lines[] = [
                'id' => null,
                'category' => (string) CostCategory::query()->whereKey($extra['cost_category_id'] ?? 0)->value('name'),
                'description' => (string) ($extra['description'] ?? ''),
                'before' => 0,
                'after' => Money::toCents((string) $extra['amount']),
                'treatment' => ParticipantRelease::LINE_ADDED,
            ];
        }

        $before = array_sum(array_column($lines, 'before'));
        $after = array_sum(array_column($lines, 'after'));
        $activityBefore = Money::toCents($activity->estimated_total);
        $reduced = collect($lines)->sum(fn ($l) => max(0, $l['before'] - $l['after']));

        return [
            'lines' => $lines,
            'before' => $before,
            'after' => $after,
            'change' => $after - $before,
            'activity_before' => $activityBefore,
            'activity_after' => $activityBefore + ($after - $before),
            'approved' => $activity->approved_estimated_total !== null ? Money::toCents($activity->approved_estimated_total) : null,
            'days_before' => $participant->plannedDays($activity),
            'days_after' => $daysAfter,
            'available_from' => $lastDay->copy()->addDay(),
            // Only if the amounts were already paid out: for Finance to recover. Projected, not realised.
            'recovery' => $reduced,
        ];
    }

    /**
     * @param  array{kind: string, reason: string, last_day: Carbon, initiated_by_name: string, destination_activity_id?: ?int, revised?: array<int, string|null>, revision_reason?: ?string, extra?: array{amount?: ?string, cost_category_id?: ?int, description?: ?string}}  $data
     */
    public function release(ActivityParticipant $participant, array $data, User $user): ParticipantRelease
    {
        if (! $user->can('activities.execute')) {
            throw new AuthorizationException('Only the Office of the CEO can recall, release or reassign an officer.');
        }

        $reason = trim($data['reason']);
        if ($reason === '' || trim($data['initiated_by_name']) === '') {
            throw new ActivityWorkflowException('Give the reason and who asked for the change.');
        }

        if (! array_key_exists($data['kind'], ParticipantRelease::KINDS)) {
            throw new ActivityWorkflowException('Choose recall, release or reassignment.');
        }

        $revised = collect($data['revised'] ?? [])->filter(fn ($v) => filled($v))->all();
        if (($revised !== [] || filled($data['extra']['amount'] ?? null)) && blank($data['revision_reason'] ?? null)) {
            throw new ActivityWorkflowException('Say why the other costs change.');
        }

        if ($data['kind'] === ParticipantRelease::KIND_REASSIGNMENT && empty($data['destination_activity_id'])) {
            throw new ActivityWorkflowException('Choose the activity the officer is reassigned to.');
        }

        return DB::transaction(function () use ($participant, $data, $user, $reason, $revised) {
            // Lock the officer's row on this activity; a second release of the same officer waits, then sees the new end.
            $participant = ActivityParticipant::query()->whereKey($participant->id)->lockForUpdate()->firstOrFail();
            $activity = Activity::query()->whereKey($participant->activity_id)->lockForUpdate()->firstOrFail();
            $participant->setRelation('activity', $activity);

            $preview = $this->preview($participant, $data['last_day'], $revised, $data['extra'] ?? []);
            $originalStart = $participant->startOn($activity)->copy();
            $originalEnd = $participant->endOn($activity)->copy();

            $participant->update([
                'start_date' => $originalStart,
                'end_date' => $data['last_day'],
                'days_planned' => $preview['days_after'],
            ]);
            $this->dsa->recalculate($activity);

            foreach ($revised as $lineId => $amount) {
                $participant->costs()->where('is_computed', false)->whereKey($lineId)->firstOrFail()
                    ->update(['estimated_amount' => Money::fromCents(Money::toCents((string) $amount))]);
            }

            if (filled($data['extra']['amount'] ?? null)) {
                $activity->costs()->create([
                    'cost_category_id' => $data['extra']['cost_category_id'],
                    'activity_participant_id' => $participant->id,
                    'description' => $data['extra']['description'] ?? null,
                    'estimated_amount' => Money::fromCents(Money::toCents((string) $data['extra']['amount'])),
                    'recorded_by' => $user->id,
                ]);
            }

            $this->costing->refresh($activity);
            $activityAfter = Money::toCents($activity->estimated_total);
            $costAfter = $participant->costs()->get(['estimated_amount'])->sum(fn (ActivityCost $c) => Money::toCents($c->estimated_amount));

            $release = ParticipantRelease::create([
                'activity_id' => $activity->id,
                'activity_participant_id' => $participant->id,
                'staff_id' => $participant->staff_id,
                'kind' => $data['kind'],
                'reason' => $reason.(filled($data['revision_reason'] ?? null) ? ' Other costs: '.trim($data['revision_reason']) : ''),
                'original_start_date' => $originalStart,
                'original_end_date' => $originalEnd,
                'last_day' => $data['last_day'],
                'destination_activity_id' => $data['destination_activity_id'] ?? null,
                'initiated_by_name' => trim($data['initiated_by_name']),
                'recorded_by' => $user->id,
                'participant_cost_before' => Money::fromCents($preview['before']),
                'participant_cost_after' => Money::fromCents($costAfter),
                'activity_total_before' => Money::fromCents($preview['activity_before']),
                'activity_total_after' => Money::fromCents($activityAfter),
                'approved_total' => $activity->approved_estimated_total,
                'lines' => $preview['lines'],
            ]);

            $summary = sprintf('%s: %s, last day %s (was %s); planned cost %s to %s',
                $participant->displayName(), strtolower($release->kindLabel()), $data['last_day']->format('d M Y'), $originalEnd->format('d M Y'),
                Money::format($preview['before']), Money::format($costAfter));

            $this->lifecycle->recordAmendment($activity, ActivityAmendment::KIND_RELEASE, $summary, $reason, $user,
                $this->lifecycle->costBeyondTolerance($activity),
                ['end_date' => $originalEnd->toDateString(), 'estimated_total' => Money::fromCents($preview['activity_before'])],
                ['end_date' => $data['last_day']->toDateString(), 'estimated_total' => Money::fromCents($activityAfter)]);

            if (! empty($data['destination_activity_id'])) {
                $this->placeOnDestination($participant, $activity, $release, $user);
            }

            return $release;
        });
    }

    /** The reassigned officer joins the other activity from the day after their last day here. */
    private function placeOnDestination(ActivityParticipant $participant, Activity $from, ParticipantRelease $release, User $user): void
    {
        $destination = Activity::query()->findOrFail($release->destination_activity_id);

        if ($destination->is($from) || $destination->status->isReadOnly() || $destination->status->isDelivered()) {
            throw new ActivityWorkflowException('Choose an activity that has not ended, been closed or been cancelled.');
        }

        $available = $release->availableFrom();
        if ($destination->end_date->lt($available)) {
            throw new ActivityWorkflowException("{$destination->reference} ends before the officer is free on {$available->format('d M Y')}.");
        }

        $amendment = $destination->status->requiresAmendment() ? "Reassigned from {$from->reference}: {$release->reason}" : null;
        $joinsLate = $destination->start_date->lt($available);

        // Joining part-way: the days already spent on the other activity are the reason, and are then dropped.
        $this->editor->addStaff($destination, [$participant->staff_id], ParticipantRole::Member, $user,
            $joinsLate ? [$participant->staff_id => "Reassigned from {$from->reference} from {$available->format('d M Y')}"] : [], $amendment);

        if ($joinsLate) {
            $joined = $destination->participants()->where('staff_id', $participant->staff_id)->sole();
            $this->editor->setParticipantDates($joined, $available, $destination->end_date, $user, $amendment);
            $joined->update(['conflict_reason' => null]);
        }
    }

    private function assertDates(ActivityParticipant $participant, Activity $activity, Carbon $lastDay): void
    {
        if (! self::canRelease($activity)) {
            throw new ActivityWorkflowException('An officer can be released only from an approved or in-progress activity. Before approval, change their days instead.');
        }

        if ($participant->is_external || ! $participant->status->isExpected()) {
            throw new ActivityWorkflowException('Only an officer still on the team can be released.');
        }

        $start = $participant->startOn($activity);
        $end = $participant->endOn($activity);

        if ($lastDay->lt($start) || $lastDay->gte($end)) {
            throw new ActivityWorkflowException(sprintf('The last day must be from %s up to the day before %s. To take them off the activity entirely, remove them from the team.', $start->format('d M Y'), $end->format('d M Y')));
        }
    }
}
