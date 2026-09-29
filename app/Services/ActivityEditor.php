<?php

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipationStatus;
use App\Exceptions\ActivityWorkflowException;
use App\Models\Activity;
use App\Models\ActivityAmendment;
use App\Models\ActivityCost;
use App\Models\ActivityLocation;
use App\Models\ActivityParticipant;
use App\Models\Staff;
use App\Models\User;
use App\Support\Money;
use App\Support\WorkingDays;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creating and changing an activity's details, locations, participants and
 * planned costs. Before submission everything is freely editable; after
 * submission or approval, changes to people and planned cost are recorded as
 * amendments and may send the activity back to the CEO (PT-07, BR-10).
 */
class ActivityEditor
{
    public function __construct(
        private ActivityLifecycle $lifecycle,
        private ActivityCosting $costing,
        private DsaCalculator $dsa,
        private ParticipantChecks $checks,
        private AppSettings $settings,
    ) {}

    /**
     * PL-01 / PL-02 / CD-05 / CD-06.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?User $user, ?string $actorName = null): Activity
    {
        if ($user) {
            $this->authorize($user, 'activities.manage');
        }

        return DB::transaction(function () use ($attributes, $user, $actorName) {
            $activity = new Activity($this->withDates($attributes));
            $activity->created_by = $user?->id;
            $activity->forceFill($this->flags($activity->start_date, today()));
            $activity->save();

            $this->lifecycle->begin($activity, $user, $actorName);

            return $activity;
        });
    }

    /**
     * Edit the details of an activity. Date changes after submission go
     * through ActivityLifecycle::postpone()/extend() instead.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Activity $activity, array $attributes, User $user): Activity
    {
        $this->authorize($user, 'activities.manage');
        $this->assertEditable($activity);

        $attributes = $this->withDates($attributes + ['start_date' => $activity->start_date, 'days' => $activity->days]);

        if (! $activity->status->isFreelyEditable()
            && ($activity->start_date->toDateString() !== Carbon::parse($attributes['start_date'])->toDateString() || (int) $attributes['days'] !== $activity->days)) {
            throw new ActivityWorkflowException('Use Postpone or Extend to change the dates of a submitted or approved activity.');
        }

        DB::transaction(function () use ($activity, $attributes) {
            $datesChanged = $activity->start_date->toDateString() !== Carbon::parse($attributes['start_date'])->toDateString()
                || (int) $attributes['days'] !== $activity->days;

            $activity->fill($attributes);
            if ($activity->status->isFreelyEditable()) {
                $activity->forceFill($this->flags($activity->start_date, $activity->created_at ?? today()));
            }
            $activity->save();

            if ($datesChanged) {
                $this->dsa->recalculate($activity);
            }
        });

        return $activity;
    }

    /** @param  array{region_id?: ?int, county_id?: ?int, constituency_id?: ?int, venue?: ?string}  $data */
    public function addLocation(Activity $activity, array $data, User $user): ActivityLocation
    {
        $this->authorize($user, 'activities.manage');
        $this->assertEditable($activity);

        if (blank($data['region_id'] ?? null) && blank($data['county_id'] ?? null) && blank($data['constituency_id'] ?? null) && blank($data['venue'] ?? null)) {
            throw new ActivityWorkflowException('Choose a region, county, constituency or venue.');
        }

        $location = $activity->locations()->create($data);
        $this->dsa->recalculate($activity);

        return $location;
    }

    public function removeLocation(ActivityLocation $location, User $user): void
    {
        $this->authorize($user, 'activities.manage');
        $activity = $location->loadMissing('activity')->activity;
        $this->assertEditable($activity);
        $location->delete();
        $this->dsa->recalculate($activity);
    }

    /**
     * PT-01..PT-04 / PT-07. Adds staff (by id) to the activity.
     *
     * @param  list<int>  $staffIds
     * @param  array<int, string>  $conflictReasons  staff id => reason, required where a conflict exists
     * @return array{added: int, warnings: list<string>, returned: bool}
     */
    public function addStaff(Activity $activity, array $staffIds, ParticipantRole $role, User $user, array $conflictReasons = [], ?string $amendmentReason = null): array
    {
        $this->authorize($user, 'activities.manage');
        $this->assertEditable($activity);

        $staff = Staff::query()->whereIn('id', $staffIds)->get()->keyBy('id');
        $existing = $activity->participants()->whereIn('staff_id', $staffIds)->pluck('staff_id')->all();
        $toAdd = $staff->except($existing);

        if ($toAdd->isEmpty()) {
            return ['added' => 0, 'warnings' => [], 'returned' => false];
        }

        foreach ($toAdd as $person) {
            if ($this->checks->conflicts($person->id, $activity->start_date, $activity->end_date, $activity->id)->isNotEmpty()
                && blank($conflictReasons[$person->id] ?? null)) {
                throw new ActivityWorkflowException("{$person->name} is already on another approved activity on these dates. Give a reason to proceed.");
            }
        }

        $needsAmendment = $activity->status->requiresAmendment();
        if ($needsAmendment && blank($amendmentReason)) {
            throw new ActivityWorkflowException('Give a reason for changing the team of a submitted or approved activity.');
        }

        $warnings = [];
        $returned = false;

        DB::transaction(function () use ($activity, $toAdd, $role, $user, $conflictReasons, $needsAmendment, $amendmentReason, &$warnings, &$returned) {
            foreach ($toAdd as $person) {
                $participant = new ActivityParticipant([
                    'activity_id' => $activity->id,
                    'staff_id' => $person->id,
                    'is_external' => false,
                    'role' => $role,
                    'status' => ParticipationStatus::Nominated,
                    'conflict_reason' => $conflictReasons[$person->id] ?? null,
                ]);
                $participant->snapshotFrom($person)->save();

                $flags = $this->checks->fieldDayFlags($person, $activity);
                if ($flags['over_quarter'] || $flags['over_year']) {
                    $warnings[] = sprintf('%s would reach %d field days this quarter (limit %d) and %d this year (limit %d).', $person->name, $flags['quarter'], $flags['quarter_limit'], $flags['year'], $flags['year_limit']);
                }
            }

            $warnings = array_merge($warnings, $this->dsa->recalculate($activity));

            if ($needsAmendment) {
                $names = $toAdd->pluck('name')->implode(', ');
                $returned = $this->lifecycle->recordAmendment(
                    $activity, ActivityAmendment::KIND_PARTICIPANTS, "Added {$names}", (string) $amendmentReason, $user,
                    $toAdd->count() > $this->settings->int('tolerance_participant_changes') || $this->lifecycle->costBeyondTolerance($activity),
                    after: ['added' => $toAdd->pluck('id')->values()->all()],
                );
            }
        });

        return ['added' => $toAdd->count(), 'warnings' => $warnings, 'returned' => $returned];
    }

    /**
     * PT-03 / Q4: a non-staff participant.
     *
     * @param  array{external_name: string, external_organisation?: ?string, external_category?: mixed}  $data
     */
    public function addExternal(Activity $activity, array $data, ParticipantRole $role, User $user, ?string $amendmentReason = null): ActivityParticipant
    {
        $this->authorize($user, 'activities.manage');
        $this->assertEditable($activity);

        if ($activity->status->requiresAmendment() && blank($amendmentReason)) {
            throw new ActivityWorkflowException('Give a reason for changing the team of a submitted or approved activity.');
        }

        return DB::transaction(function () use ($activity, $data, $role, $user, $amendmentReason) {
            $participant = $activity->participants()->create($data + [
                'is_external' => true,
                'role' => $role,
                'status' => ParticipationStatus::Nominated,
            ]);
            $this->costing->refresh($activity);

            if ($activity->status->requiresAmendment()) {
                $this->lifecycle->recordAmendment($activity, ActivityAmendment::KIND_PARTICIPANTS, "Added external participant {$data['external_name']}", (string) $amendmentReason, $user, false);
            }

            return $participant;
        });
    }

    public function removeParticipant(ActivityParticipant $participant, User $user, ?string $amendmentReason = null): bool
    {
        $this->authorize($user, 'activities.manage');
        $activity = $participant->loadMissing('activity')->activity;
        $this->assertEditable($activity);

        if ($activity->status->requiresAmendment() && blank($amendmentReason)) {
            throw new ActivityWorkflowException('Give a reason for changing the team of a submitted or approved activity.');
        }

        return DB::transaction(function () use ($participant, $activity, $user, $amendmentReason) {
            $name = $participant->displayName();
            $participant->delete();
            $this->costing->refresh($activity);

            if (! $activity->status->requiresAmendment()) {
                return false;
            }

            return $this->lifecycle->recordAmendment($activity, ActivityAmendment::KIND_PARTICIPANTS, "Removed {$name}", (string) $amendmentReason, $user,
                $this->settings->int('tolerance_participant_changes') < 1);
        });
    }

    /**
     * EX-02: attendance, and days actually spent.
     */
    public function recordAttendance(ActivityParticipant $participant, ParticipationStatus $status, ?int $daysAttended, User $user): void
    {
        $this->authorize($user, 'activities.execute');

        $activity = $participant->loadMissing('activity')->activity;
        if (! in_array($activity->status, [ActivityStatus::InProgress, ActivityStatus::Completed, ActivityStatus::ReportReceived], true)) {
            throw new ActivityWorkflowException('Attendance is recorded once the activity has started.');
        }

        $participant->update([
            'status' => $status,
            'days_attended' => $status === ParticipationStatus::Attended ? ($daysAttended ?? $participant->days_planned ?? $activity->days) : null,
        ]);
        $this->costing->refresh($activity);
    }

    /**
     * CB-02 / CB-03: a planned cost line (travel, venue, …). DSA lines are computed by DsaCalculator.
     *
     * @param  array{cost_category_id: int, activity_participant_id?: ?int, description?: ?string, estimated_amount: string, travel_mode?: ?string}  $data
     * @return bool whether the change sent the activity back to the CEO
     */
    public function savePlannedCost(Activity $activity, array $data, User $user, ?ActivityCost $line = null, ?string $amendmentReason = null): bool
    {
        $this->authorize($user, 'activities.manage');
        $this->assertEditable($activity);

        if ($activity->status->isDelivered()) {
            throw new ActivityWorkflowException('Planned costs are fixed once the activity is completed; record actual amounts instead.');
        }

        if ($activity->status->requiresAmendment() && blank($amendmentReason)) {
            throw new ActivityWorkflowException('Give a reason for changing the planned cost of a submitted or approved activity.');
        }

        $data['estimated_amount'] = Money::fromCents(Money::toCents($data['estimated_amount']));
        $before = $activity->estimated_total;

        return DB::transaction(function () use ($activity, $data, $user, $line, $amendmentReason, $before) {
            if ($line) {
                abort_if($line->is_computed, 422, 'Override a computed DSA amount instead of editing it.');
                $line->update($data);
            } else {
                $activity->costs()->create($data + ['recorded_by' => $user->id]);
            }
            $this->costing->refresh($activity);

            if (! $activity->status->requiresAmendment()) {
                return false;
            }

            return $this->lifecycle->recordAmendment($activity, ActivityAmendment::KIND_COST,
                sprintf('Planned cost changed from %s to %s', Money::format(Money::toCents($before)), Money::format(Money::toCents($activity->estimated_total))),
                (string) $amendmentReason, $user, $this->lifecycle->costBeyondTolerance($activity),
                ['estimated_total' => $before], ['estimated_total' => $activity->estimated_total]);
        });
    }

    public function removePlannedCost(ActivityCost $line, User $user, ?string $amendmentReason = null): bool
    {
        $activity = $line->loadMissing('activity')->activity;

        if ($line->is_computed) {
            throw new ActivityWorkflowException('Computed DSA follows the participants; remove the participant instead.');
        }

        $this->authorize($user, 'activities.manage');
        $this->assertEditable($activity);

        if ($activity->status->requiresAmendment() && blank($amendmentReason)) {
            throw new ActivityWorkflowException('Give a reason for changing the planned cost of a submitted or approved activity.');
        }

        return DB::transaction(function () use ($line, $activity, $user, $amendmentReason) {
            $before = $activity->estimated_total;
            $line->delete();
            $this->costing->refresh($activity);

            return $activity->status->requiresAmendment()
                && $this->lifecycle->recordAmendment($activity, ActivityAmendment::KIND_COST, 'Removed a planned cost line', (string) $amendmentReason, $user, false,
                    ['estimated_total' => $before], ['estimated_total' => $activity->estimated_total]);
        });
    }

    /** FR-01: the actual amount from Finance returns. */
    public function recordActual(ActivityCost $line, ?string $amount, ?string $financeReference, User $user): void
    {
        $this->authorize($user, 'activities.execute');

        $line->loadMissing('activity');

        if (! $line->activity->status->isDelivered() && $line->activity->status !== ActivityStatus::InProgress) {
            throw new ActivityWorkflowException('Actual costs are recorded once the activity is under way.');
        }

        $line->update([
            'actual_amount' => blank($amount) ? null : Money::fromCents(Money::toCents($amount)),
            'finance_reference' => $financeReference ?: $line->finance_reference,
        ]);
        $this->costing->refresh($line->activity);
    }

    /**
     * BR-01: end date = start + days − 1; nights = days − 1.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withDates(array $attributes): array
    {
        $start = Carbon::parse($attributes['start_date']);
        $days = max(1, (int) ($attributes['days'] ?? 1));

        return array_merge($attributes, [
            'start_date' => $start->toDateString(),
            'days' => $days,
            'nights' => $days - 1,
            'end_date' => $start->copy()->addDays($days - 1)->toDateString(),
        ]);
    }

    /**
     * CD-05: late notice, judged against the day the activity was recorded.
     * (CD-06 retrospective is set when the decision is recorded: see ActivityLifecycle.)
     *
     * @return array{is_late_notice: bool}
     */
    private function flags(Carbon $start, Carbon $recordedOn): array
    {
        $recordedOn = $recordedOn->copy()->startOfDay();

        return [
            'is_late_notice' => $start->gte($recordedOn) && WorkingDays::between($recordedOn, $start) < $this->settings->int('late_notice_working_days'),
        ];
    }

    private function assertEditable(Activity $activity): void
    {
        if ($activity->status->isReadOnly()) {
            throw new ActivityWorkflowException("A {$activity->status->label()} activity is read only.");
        }
    }

    private function authorize(User $user, string $permission): void
    {
        if (! $user->can($permission)) {
            throw new AuthorizationException('You are not permitted to do this.');
        }
    }
}
