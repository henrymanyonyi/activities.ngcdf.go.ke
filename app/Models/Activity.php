<?php

namespace App\Models;

use App\Enums\ActivityStatus;
use App\Enums\DecisionMode;
use App\Enums\ImprestStatus;
use App\Enums\Rag;
use App\Models\Concerns\Auditable;
use App\Support\FinancialYear;
use App\Support\Money;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    // status, decision/approval fields, execution timestamps, flags and the
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

    public function isReportOverdue(): bool
    {
        return $this->status === ActivityStatus::Completed && $this->report_due_on?->isBefore(today());
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
