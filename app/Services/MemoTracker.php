<?php

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Enums\ReviewOffice;
use App\Models\Activity;
use App\Models\ActivityStatusHistory;
use App\Models\User;
use App\Support\Money;
use App\Support\WorkingDays;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Where memos are and how long they took: the tabs on the Memos board for
 * each role, the counts behind them, and the figures for the memo report.
 * Every count is of activities (one row per memo), never of history rows,
 * so a resubmitted memo is counted once.
 */
class MemoTracker
{
    public const PERSPECTIVE_CEO = 'ceo';

    public const PERSPECTIVE_REVIEWER = 'reviewer';

    public const PERSPECTIVE_DEPARTMENT = 'department';

    public function __construct(private AppSettings $settings) {}

    public function perspective(User $user): ?string
    {
        return match (true) {
            $user->can('activities.decide') => self::PERSPECTIVE_CEO,
            $user->can('memos.review') => self::PERSPECTIVE_REVIEWER,
            $user->can('memos.originate') => self::PERSPECTIVE_DEPARTMENT,
            default => null,
        };
    }

    /**
     * The board's tabs for this user, in order; the first is what needs their action.
     *
     * @return array<string, array{label: string, icon: string, empty: string}>
     */
    public function tabs(User $user): array
    {
        return match ($this->perspective($user)) {
            self::PERSPECTIVE_CEO => [
                'awaiting' => ['label' => 'Awaiting my approval', 'icon' => 'fa-hourglass-half', 'empty' => 'Nothing is waiting for your decision.'],
                'returned' => ['label' => 'Returned for clarification', 'icon' => 'fa-rotate-left', 'empty' => 'No memo is back with the Office of the CEO for clarification.'],
                'approved' => ['label' => 'Approved and authorised', 'icon' => 'fa-circle-check', 'empty' => 'No approved memos yet.'],
                'declined' => ['label' => 'Declined', 'icon' => 'fa-circle-xmark', 'empty' => 'No declined memos.'],
                'recent' => ['label' => 'Recent decisions', 'icon' => 'fa-gavel', 'empty' => 'No decisions in the last 30 days.'],
                'in_review' => ['label' => 'In review', 'icon' => 'fa-magnifying-glass', 'empty' => 'No memo is with the Chief of Staff or Assistant.'],
            ],
            self::PERSPECTIVE_REVIEWER => [
                'mine' => ['label' => 'Awaiting my review', 'icon' => 'fa-inbox', 'empty' => 'Nothing is waiting for your review.'],
                'ceo_returned' => ['label' => 'Returned by the CEO', 'icon' => 'fa-rotate-left', 'empty' => 'The CEO has not returned any memo.'],
                'with_hod' => ['label' => 'Returned to Heads of Department', 'icon' => 'fa-reply', 'empty' => 'No memo is back with a Head of Department.'],
                'forwarded' => ['label' => 'Forwarded to the CEO', 'icon' => 'fa-share', 'empty' => 'No memo is waiting for the CEO.'],
                'on_behalf' => ['label' => 'Prepared for Heads of Department', 'icon' => 'fa-user-pen', 'empty' => 'No memos have been prepared on a Head of Department\'s behalf.'],
                'recent' => ['label' => 'Recently processed', 'icon' => 'fa-clock-rotate-left', 'empty' => 'You have not acted on a memo in the last 30 days.'],
                'in_review' => ['label' => 'All under review', 'icon' => 'fa-magnifying-glass', 'empty' => 'No memo is under review.'],
            ],
            self::PERSPECTIVE_DEPARTMENT => [
                'drafts' => ['label' => 'Drafts', 'icon' => 'fa-pen-ruler', 'empty' => 'No drafts. Start with "Create new activity memo".'],
                'returned' => ['label' => 'Returned for correction', 'icon' => 'fa-reply', 'empty' => 'Nothing has been returned to you.'],
                'pending' => ['label' => 'Awaiting review and decision', 'icon' => 'fa-hourglass-half', 'empty' => 'No memo is with the Office of the CEO.'],
                'approved' => ['label' => 'Approved and authorised', 'icon' => 'fa-circle-check', 'empty' => 'No approved memos yet.'],
                'declined' => ['label' => 'Declined', 'icon' => 'fa-circle-xmark', 'empty' => 'No declined memos.'],
                'all' => ['label' => 'All', 'icon' => 'fa-layer-group', 'empty' => 'Your department has no memos yet.'],
            ],
            default => [],
        };
    }

    /** @return Builder<Activity> */
    public function query(User $user, string $tab): Builder
    {
        $query = Activity::query()->visibleTo($user);
        $office = ReviewOffice::forUser($user);
        $since = now()->subDays(30);

        return match ($tab) {
            'awaiting', 'forwarded' => $query->status(ActivityStatus::AwaitingDecision),
            'returned' => $this->perspective($user) === self::PERSPECTIVE_DEPARTMENT ? $query->status(ActivityStatus::ReturnedToHod) : $query->status(ActivityStatus::Returned),
            'ceo_returned' => $query->status(ActivityStatus::Returned),
            'approved' => $query->status(...$this->approvedStatuses()),
            'declined' => $query->status(ActivityStatus::Declined),
            'in_review' => $query->status(ActivityStatus::UnderReview),
            'mine' => $query->status(ActivityStatus::UnderReview)->where('review_office', ($office ?? ReviewOffice::ChiefOfStaff)->value),
            'with_hod' => $query->status(ActivityStatus::ReturnedToHod),
            'on_behalf' => $query->where(fn (Builder $q) => $q->whereNotNull('responsible_hod_name')->orWhereNotNull('responsible_hod_id'))
                ->where(fn (Builder $q) => $q->whereNull('responsible_hod_id')->orWhereColumn('responsible_hod_id', '!=', 'created_by')),
            'recent' => $this->perspective($user) === self::PERSPECTIVE_CEO
                ? $query->whereHas('decisions', fn (Builder $q) => $q->where('created_at', '>=', $since))
                : $query->where(fn (Builder $q) => $q
                    ->whereHas('statusHistory', fn (Builder $h) => $h->where('user_id', $user->id)->where('created_at', '>=', $since))
                    ->orWhereHas('reviewNotes', fn (Builder $n) => $n->where('user_id', $user->id)->where('created_at', '>=', $since))),
            'drafts' => $query->status(ActivityStatus::Draft),
            'pending' => $query->status(ActivityStatus::UnderReview, ActivityStatus::AwaitingDecision, ActivityStatus::Returned),
            default => $query,
        };
    }

    /** @return array<string, int> tab => number of memos */
    public function counts(User $user): array
    {
        return collect($this->tabs($user))->map(fn ($tab, string $key) => $this->query($user, $key)->count())->all();
    }

    /** How many memos are waiting for this user to act (the sidebar badge). */
    public function actionCount(User $user): int
    {
        return match ($this->perspective($user)) {
            self::PERSPECTIVE_CEO => $this->query($user, 'awaiting')->count(),
            self::PERSPECTIVE_REVIEWER => $this->query($user, 'mine')->count() + $this->query($user, 'ceo_returned')->count(),
            self::PERSPECTIVE_DEPARTMENT => $this->query($user, 'returned')->count(),
            default => 0,
        };
    }

    /** @return list<ActivityStatus> */
    public function approvedStatuses(): array
    {
        return array_values(array_filter(ActivityStatus::cases(), fn (ActivityStatus $s) => $s->isApprovedOrLater()));
    }

    /**
     * Management figures for a set of memos.
     *
     * @param  Builder<Activity>  $memos
     * @return array{originated: int, with_department: int, with_acos: int, with_cos: int, awaiting_ceo: int, returned_by_ceo: int, approved: int, declined: int, ever_returned: int, pending_too_long: int, proposed_cents: int, approved_cents: int}
     */
    public function summary(Builder $memos): array
    {
        $rows = (clone $memos)->get(['id', 'status', 'review_office', 'created_by', 'responsible_hod_id', 'requested_budget', 'estimated_total', 'approved_estimated_total']);
        $count = fn (ActivityStatus ...$statuses) => $rows->filter(fn (Activity $a) => in_array($a->status, $statuses, true))->count();
        $pending = $rows->filter(fn (Activity $a) => in_array($a->status, [ActivityStatus::UnderReview, ActivityStatus::AwaitingDecision], true));
        $since = $this->lastMoves($pending->pluck('id'));

        return [
            'originated' => $rows->count(),
            'with_department' => $count(ActivityStatus::Draft, ActivityStatus::ReturnedToHod),
            'with_acos' => $rows->filter(fn (Activity $a) => $a->status === ActivityStatus::UnderReview && $a->review_office === ReviewOffice::AssistantChiefOfStaff)->count(),
            'with_cos' => $rows->filter(fn (Activity $a) => $a->status === ActivityStatus::UnderReview && $a->review_office === ReviewOffice::ChiefOfStaff)->count(),
            'awaiting_ceo' => $count(ActivityStatus::AwaitingDecision),
            'returned_by_ceo' => $count(ActivityStatus::Returned),
            'approved' => $count(...$this->approvedStatuses()),
            'declined' => $count(ActivityStatus::Declined),
            'ever_returned' => ActivityStatusHistory::query()->whereIn('activity_id', $rows->pluck('id'))
                ->whereIn('to_status', ActivityStatus::values(ActivityStatus::ReturnedToHod, ActivityStatus::Returned))
                ->distinct()->count('activity_id'),
            'pending_too_long' => $pending->filter(fn (Activity $a) => isset($since[$a->id]) && $this->isTooLong($since[$a->id]))->count(),
            'proposed_cents' => $rows->reject(fn (Activity $a) => $a->status->isApprovedOrLater() || in_array($a->status, [ActivityStatus::Declined, ActivityStatus::Cancelled], true))
                ->sum(fn (Activity $a) => $this->proposedCents($a)),
            'approved_cents' => $rows->filter(fn (Activity $a) => $a->status->isApprovedOrLater())
                ->sum(fn (Activity $a) => Money::toCents($a->approved_estimated_total ?? $a->estimated_total)),
        ];
    }

    /** The costed plan once the team is costed, otherwise the department's own estimate. */
    public function proposedCents(Activity $activity): int
    {
        $costed = Money::toCents($activity->estimated_total);

        return $costed > 0 ? $costed : Money::toCents($activity->requested_budget ?? '0');
    }

    /**
     * Days spent with the department, in review and with the CEO, summed over
     * every round (a memo returned and resubmitted adds to each stage).
     *
     * @param  Collection<int, ActivityStatusHistory>  $history  the activity's history, any order
     * @return array{department: float, review: float, ceo: float, submitted_at: ?Carbon, decided_at: ?Carbon}
     */
    public function stageDurations(Collection $history): array
    {
        $stage = fn (?ActivityStatus $s) => match ($s) {
            ActivityStatus::Draft, ActivityStatus::ReturnedToHod => 'department',
            ActivityStatus::UnderReview, ActivityStatus::Returned => 'review',
            ActivityStatus::AwaitingDecision => 'ceo',
            default => null,
        };

        $totals = ['department' => 0.0, 'review' => 0.0, 'ceo' => 0.0];
        $entries = $history->sortBy('id')->values();
        $submittedAt = null;
        $decidedAt = null;

        foreach ($entries as $i => $entry) {
            $end = $entries[$i + 1]->created_at ?? now();
            if ($key = $stage($entry->to_status)) {
                $totals[$key] += round($entry->created_at->diffInMinutes($end) / 1440, 2);
            }
            if (! $submittedAt && in_array($entry->to_status, [ActivityStatus::UnderReview, ActivityStatus::AwaitingDecision], true)) {
                $submittedAt = $entry->created_at;
            }
            if (in_array($entry->to_status, [ActivityStatus::Approved, ActivityStatus::Declined], true) && $entry->from_status !== null) {
                $decidedAt = $entry->created_at;
            }
        }

        return array_map(fn ($v) => is_float($v) ? round($v, 1) : $v, $totals) + ['submitted_at' => $submittedAt, 'decided_at' => $decidedAt];
    }

    /** Pending with a reviewer or the CEO for more working days than Settings allow. */
    public function isTooLong(Carbon $since): bool
    {
        return WorkingDays::between($since->copy()->startOfDay(), today()) >= $this->settings->int('memo_review_reminder_working_days');
    }

    /**
     * When each memo last moved (its current stage began).
     *
     * @param  Collection<int, int>  $ids
     * @return array<int, Carbon>
     */
    public function lastMoves(Collection $ids): array
    {
        if ($ids->isEmpty()) {
            return [];
        }

        return ActivityStatusHistory::query()->whereIn('activity_id', $ids)
            ->selectRaw('activity_id, MAX(created_at) as moved_at')->groupBy('activity_id')
            ->pluck('moved_at', 'activity_id')
            ->map(fn ($at) => Carbon::parse($at))
            ->all();
    }
}
