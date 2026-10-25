<?php

namespace App\Models;

use App\Enums\ActivityStatus;
use App\Enums\DecisionMode;
use App\Enums\ImprestStatus;
use App\Enums\Rag;
use App\Enums\ReviewOffice;
use App\Models\Concerns\Auditable;
use App\Support\FinancialYear;
use App\Support\Money;
use App\Support\WorkingDays;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One field activity (FRD 11, "Activity"). Status changes go through
 * App\Services\ActivityLifecycle; totals are maintained by
 * App\Services\ActivityCosting — do not write either directly.
 *
 * Soft deletes exist only so a Draft can be discarded (CF-10); every other
 * activity is cancelled, never deleted.
 */
class Activity extends Model
{
    use Auditable;

    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'reference',
        'title',
        'purpose',
        'expected_outputs',
        'justification',
        'risks',
        'memo_sections',
        'requested_budget',
        'responsible_hod_id',
        'responsible_hod_name',
        'notes',
        'activity_category_id',
        'activity_type_id',
        'programme_id',
        'budget_line_id',
        'organising_department_id',
        'target_audience',
        'source_reference',
        'source_received_on',
        'start_date',
        'end_date',
        'days',
        'nights',
        'count_externals_in_per_head',
        'imprest_reference',
        'imprest_status',
        'submission_link_id',
        'submitted_by_name',
        'submitted_by_email',
        'submitted_by_phone',
        'submitted_at',
        'created_by',
    ];

    // status, review_office, decision/approval fields, execution timestamps, flags and the
    // stored totals are deliberately not fillable: only ActivityLifecycle and
    // ActivityCosting write them.

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'status' => ActivityStatus::class,
            'approval_mode' => DecisionMode::class,
            'review_office' => ReviewOffice::class,
            'imprest_status' => ImprestStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'source_received_on' => 'date',
            'actual_start_date' => 'date',
            'actual_end_date' => 'date',
            'report_due_on' => 'date',
            'approved_at' => 'datetime',
            'submitted_at' => 'datetime',
            'commenced_at' => 'datetime',
            'completed_at' => 'datetime',
            'closed_at' => 'datetime',
            'count_externals_in_per_head' => 'boolean',
            'actuals_complete' => 'boolean',
            'is_late_notice' => 'boolean',
            'is_retrospective' => 'boolean',
            'estimated_total' => 'decimal:2',
            'actual_total' => 'decimal:2',
            'cost_total' => 'decimal:2',
            'requested_budget' => 'decimal:2',
            'memo_sections' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Reference is derived from the id, so it is unique without a sequence table.
        static::created(function (Activity $activity): void {
            $activity->reference = sprintf('FAPM-%s-%05d', FinancialYear::for($activity->start_date ?? today())->shortLabel(), $activity->id);
            $activity->saveQuietly();
        });
    }

    /** @return BelongsTo<ActivityCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ActivityCategory::class, 'activity_category_id');
    }

    /** @return BelongsTo<ActivityType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ActivityType::class, 'activity_type_id');
    }

    /** @return BelongsTo<Programme, $this> */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /** @return BelongsTo<BudgetLine, $this> */
    public function budgetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class);
    }

    /**
     * The requesting department (FRD PL-01).
     *
     * @return BelongsTo<Department, $this>
     */
    public function organisingDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'organising_department_id');
    }

    /** @return BelongsTo<SubmissionLink, $this> */
    public function submissionLink(): BelongsTo
    {
        return $this->belongsTo(SubmissionLink::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('name');
    }

    /** @return HasMany<ActivityLocation, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(ActivityLocation::class)->orderBy('id');
    }

    /** @return HasMany<ActivityParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ActivityParticipant::class);
    }

    /** @return HasMany<ActivityCost, $this> */
    public function costs(): HasMany
    {
        return $this->hasMany(ActivityCost::class);
    }

    /** @return HasMany<ActivityDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(ActivityDocument::class)->latest();
    }

    /** @return HasMany<ActivityStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(ActivityStatusHistory::class)->orderBy('id');
    }

    /** @return HasMany<CeoDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(CeoDecision::class)->orderBy('id');
    }

    /** @return HasOne<CeoDecision, $this> */
    public function latestDecision(): HasOne
    {
        return $this->hasOne(CeoDecision::class)->latestOfMany();
    }

    /**
     * The CEO's instructions to Finance and Procurement (App\Services\ActionInstructions).
     *
     * @return HasMany<ActionInstruction, $this>
     */
    public function instructions(): HasMany
    {
        return $this->hasMany(ActionInstruction::class);
    }

    /** @return HasMany<Directive, $this> */
    public function directives(): HasMany
    {
        return $this->hasMany(Directive::class)->latest();
    }

    /** @return HasOne<ReportReceipt, $this> */
    public function report(): HasOne
    {
        return $this->hasOne(ReportReceipt::class);
    }

    /** @return HasMany<ActivityAmendment, $this> */
    public function amendments(): HasMany
    {
        return $this->hasMany(ActivityAmendment::class)->latest('id');
    }

    /**
     * The Head of Department responsible for the memo. created_by is who
     * actually prepared it, which differs when the Office of the CEO records
     * it on the Head of Department's behalf.
     *
     * @return BelongsTo<User, $this>
     */
    public function responsibleHod(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_hod_id');
    }

    /** @return HasMany<ParticipantRelease, $this> */
    public function releases(): HasMany
    {
        return $this->hasMany(ParticipantRelease::class)->orderBy('id');
    }

    /**
     * Prepared in the system by the responsible Head of Department, so the
     * memo the system generates stands as the memo (no signed upload needed).
     */
    public function isDepartmentOriginated(): bool
    {
        return $this->responsible_hod_id !== null && (int) $this->responsible_hod_id === (int) $this->created_by;
    }

    /** @return HasMany<ActivityReviewNote, $this> */
    public function reviewNotes(): HasMany
    {
        return $this->hasMany(ActivityReviewNote::class)->latest('id');
    }

    public function responsibleHodLabel(): ?string
    {
        return $this->responsible_hod_name ?: $this->responsibleHod?->name;
    }

    /** Prepared by someone other than the responsible Head of Department. */
    public function isPreparedOnBehalf(): bool
    {
        return $this->responsibleHodLabel() !== null && ($this->responsible_hod_id === null || (int) $this->responsible_hod_id !== (int) $this->created_by);
    }

    /** A draft the Head of Department is still preparing (not one the Office of the CEO is recording). */
    public function isDepartmentDraft(): bool
    {
        return $this->status === ActivityStatus::Draft && $this->isDepartmentOriginated();
    }

    /** Who holds the memo now. */
    public function currentHolder(): string
    {
        $department = $this->organisingDepartment?->name;

        return match ($this->status) {
            ActivityStatus::Draft => $this->isDepartmentDraft() ? 'Head of Department'.($department ? ", {$department}" : '') : 'Office of the CEO',
            ActivityStatus::ReturnedToHod => 'Head of Department'.($department ? ", {$department}" : ''),
            ActivityStatus::UnderReview => $this->review_office?->label() ?? 'Office of the CEO',
            ActivityStatus::AwaitingDecision => 'Chief Executive Officer',
            ActivityStatus::Returned => 'Chief of Staff and Assistant Chief of Staff',
            ActivityStatus::Declined => 'Nobody: the CEO declined it',
            default => 'Nobody: the CEO has decided',
        };
    }

    /** What has to happen next, in plain words. */
    public function nextAction(): string
    {
        return match ($this->status) {
            ActivityStatus::Draft => $this->isDepartmentDraft() ? 'Complete the memo and submit it for review.' : 'Complete the memo and forward it to the CEO.',
            ActivityStatus::UnderReview => 'Review the memo, then forward it or return it for correction.',
            ActivityStatus::ReturnedToHod => 'Make the corrections asked for and resubmit.',
            ActivityStatus::AwaitingDecision => 'The CEO approves and authorises, declines, or returns it.',
            ActivityStatus::Returned => 'Answer the CEO\'s comments, then forward it again or return it to the Head of Department.',
            ActivityStatus::Declined => 'None. The CEO declined this memo.',
            ActivityStatus::Cancelled => 'None. The activity was cancelled.',
            default => 'None. Approved and authorised'.($this->approved_at ? ' on '.$this->approved_at->format('d M Y') : '').'.',
        };
    }

    /**
     * The most recent comment that matters to the reader: a review note, or a
     * reason given when the memo was returned, forwarded or decided.
     *
     * @return array{body: string, by: string, at: Carbon}|null
     */
    public function latestComment(): ?array
    {
        $note = $this->reviewNotes->first();
        $history = $this->statusHistory->filter(fn (ActivityStatusHistory $h) => filled($h->reason) && $h->user_id !== null)->sortByDesc('id')->first();

        $candidates = collect([
            $note ? ['body' => $note->body, 'by' => $note->user?->name ?? 'Unknown', 'at' => $note->created_at] : null,
            $history ? ['body' => $history->reason, 'by' => $history->actorLabel(), 'at' => $history->created_at] : null,
        ])->filter();

        return $candidates->sortByDesc(fn (array $c) => $c['at']->getTimestamp())->first();
    }

    public function locationSummary(): string
    {
        return $this->locations->map(fn (ActivityLocation $l) => $l->label())->filter()->implode('; ');
    }

    public function headcount(): int
    {
        return $this->staff_count + ($this->count_externals_in_per_head ? $this->external_count : 0);
    }

    /** Best-known cost (actual where entered, planned otherwise) per counted head, in cents. */
    public function costPerHead(): ?int
    {
        $heads = $this->headcount();

        return $heads > 0 ? Money::divide(Money::toCents($this->cost_total), $heads) : null;
    }

    /** Completed, past the report due date, and the department's report is not in (none yet, or returned for correction). */
    public function isReportOverdue(): bool
    {
        if ($this->status !== ActivityStatus::Completed || ! $this->report_due_on?->isBefore(today())) {
            return false;
        }
        $report = $this->reportReceipt();

        return ! $report || $report->isReturned();
    }

    /** The report, loaded if a list did not load it (lists call rag() on every row). */
    private function reportReceipt(): ?ReportReceipt
    {
        return $this->relationLoaded('report') ? $this->report : $this->report()->first();
    }

    /**
     * Whether the activity report reached the CEO within the due period
     * (Settings › Thresholds, working days after the end date). Timed from
     * the date it was first received.
     *
     * @return array{status: 'on_time'|'late'|'due'|'overdue', days: int, received_on: ?Carbon, due_on: Carbon}|null
     */
    public function reportTimeliness(): ?array
    {
        if (! $this->report_due_on) {
            return null;
        }

        $received = $this->reportReceipt()?->received_on;
        if ($received) {
            $late = WorkingDays::between($this->report_due_on, $received);

            return ['status' => $late > 0 ? 'late' : 'on_time', 'days' => $late, 'received_on' => $received, 'due_on' => $this->report_due_on];
        }

        $late = WorkingDays::between($this->report_due_on, today());

        return $late > 0
            ? ['status' => 'overdue', 'days' => $late, 'received_on' => null, 'due_on' => $this->report_due_on]
            : ['status' => 'due', 'days' => WorkingDays::between(today(), $this->report_due_on), 'received_on' => null, 'due_on' => $this->report_due_on];
    }

    /** "Submitted on time", "Submitted 2 working days late", "Due in 3 working days", "Overdue by 4 working days". */
    public function reportTimelinessLabel(): ?string
    {
        $t = $this->reportTimeliness();
        $days = fn (int $n) => $n.' working '.($n === 1 ? 'day' : 'days');

        return match ($t['status'] ?? null) {
            'on_time' => 'Submitted on time',
            'late' => 'Submitted '.$days($t['days']).' late',
            'overdue' => 'Overdue by '.$days($t['days']),
            'due' => $t['days'] === 0 ? 'Due today' : 'Due in '.$days($t['days']),
            default => null,
        };
    }

    /**
     * FRD EX-01: an approved activity whose start date has come and whose
     * commencement is not yet confirmed is amber after its first day, red after two.
     */
    public function commencementRag(): ?Rag
    {
        if ($this->status !== ActivityStatus::Approved || $this->start_date->isAfter(today())) {
            return null;
        }

        $daysLate = (int) $this->start_date->diffInDays(today());

        return match (true) {
            $daysLate >= 2 => Rag::Red,
            $daysLate >= 1 => Rag::Amber,
            default => Rag::Green,
        };
    }

    /** Overall RAG indicator for lists and dashboards (DB-06). */
    public function rag(): ?Rag
    {
        if ($rag = $this->commencementRag()) {
            return $rag;
        }

        if ($this->status === ActivityStatus::Completed && $this->report_due_on) {
            return $this->isReportOverdue() ? Rag::Red : Rag::Amber;
        }

        if ($this->status === ActivityStatus::InProgress && $this->end_date->isBefore(today())) {
            return Rag::Amber;
        }

        return in_array($this->status, [ActivityStatus::Approved, ActivityStatus::InProgress, ActivityStatus::ReportReceived, ActivityStatus::Closed], true)
            ? Rag::Green
            : null;
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeStatus(Builder $query, ActivityStatus ...$statuses): void
    {
        $query->whereIn('status', ActivityStatus::values(...$statuses));
    }

    /**
     * The memos a user may see: everything for the Office of the CEO, only
     * their own department's for a Head of Department, nothing otherwise.
     *
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can('activities.view')) {
            return;
        }

        if ($user->can('memos.originate') && $user->department_id) {
            $query->where('organising_department_id', $user->department_id);

            return;
        }

        $query->whereRaw('1 = 0');
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->can('activities.view')
            || ($user->can('memos.originate') && $user->department_id !== null && (int) $this->organising_department_id === (int) $user->department_id);
    }

    /**
     * Title, reference, source memo, or a participant's name (SR-01).
     *
     * @param  Builder<static>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);
        if ($term === '') {
            return;
        }

        $query->where(fn (Builder $q) => $q
            ->where('title', 'like', "%{$term}%")
            ->orWhere('reference', 'like', "%{$term}%")
            ->orWhere('source_reference', 'like', "%{$term}%")
            ->orWhereHas('participants', fn (Builder $p) => $p
                ->where('external_name', 'like', "%{$term}%")
                ->orWhereHas('staff', fn (Builder $s) => $s->where('name', 'like', "%{$term}%"))));
    }

    /**
     * Activities whose dates overlap the given range (inclusive).
     *
     * @param  Builder<static>  $query
     */
    public function scopeOverlapping(Builder $query, $start, $end): void
    {
        $query->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start);
    }
}
