<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\SubmissionLinkFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * A magic link. `intake`: a memo originator submits activities without an
 * account (the Office of the CEO's links, App\Services\SubmissionLinkService).
 * `delegated`: a Head of Department asks one officer on the staff list to
 * prepare one memo for the Head's review (App\Services\DelegatedMemos); it
 * opens only after a one-time code sent to that officer, and stops working
 * once the memo is submitted to the Head, or when it expires or is revoked.
 */
class SubmissionLink extends Model
{
    public const KIND_INTAKE = 'intake';

    public const KIND_DELEGATED = 'delegated';

    use Auditable;

    /** @use HasFactory<SubmissionLinkFactory> */
    use HasFactory;

    protected $fillable = [
        'token_hash',
        'token_encrypted',
        'label',
        'recipient_name',
        'recipient_email',
        'recipient_phone',
        'department_id',
        'activity_category_id',
        'instructions',
        'expires_at',
        'max_submissions',
        'submissions_count',
        'revoked_at',
        'last_opened_at',
        'open_count',
        'created_by',
        'kind',
        'staff_id',
        'activity_id',
    ];

    protected $hidden = ['token_hash', 'token_encrypted', 'otp_hash'];

    protected $attributes = [
        'kind' => self::KIND_INTAKE,
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_opened_at' => 'datetime',
            'otp_expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeIntake(Builder $query): void
    {
        $query->where('kind', self::KIND_INTAKE);
    }

    /** @param  Builder<self>  $query */
    public function scopeDelegated(Builder $query): void
    {
        $query->where('kind', self::KIND_DELEGATED);
    }

    public function isDelegated(): bool
    {
        return $this->kind === self::KIND_DELEGATED;
    }

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }

    /**
     * The memo a delegated link prepares.
     *
     * @return BelongsTo<Activity, $this>
     */
    public function memo(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'activity_id');
    }

    /** A delegated link whose memo has been submitted to the Head: it no longer opens the memo for editing. */
    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<ActivityCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ActivityCategory::class, 'activity_category_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Activity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function plainToken(): string
    {
        return Crypt::decryptString($this->token_encrypted);
    }

    public function url(): string
    {
        return $this->isDelegated()
            ? route('memo-request.show', ['token' => $this->plainToken()])
            : route('submit.show', ['token' => $this->plainToken()]);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isExhausted(): bool
    {
        return $this->max_submissions !== null && $this->submissions_count >= $this->max_submissions;
    }

    /** Can a new submission be made? Resubmitting a returned activity is checked separately. */
    public function acceptsSubmissions(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired() && ! $this->isExhausted();
    }

    /** Can the link be opened at all (to submit, or to fix a returned activity)? */
    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired() && ! $this->isCompleted();
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->isCompleted() => 'Used: memo submitted',
            $this->isRevoked() => 'Revoked',
            $this->isExpired() => 'Expired',
            $this->isExhausted() => 'Used up',
            $this->isDelegated() && $this->activity_id => 'In use: memo being prepared',
            default => 'Active',
        };
    }

    public function statusBadgeClasses(): string
    {
        return match (true) {
            $this->isCompleted() => 'bg-blue-50 text-blue-700',
            $this->isRevoked() => 'bg-red-50 text-red-700',
            $this->isExpired() => 'bg-slate-100 text-slate-500',
            $this->isExhausted() => 'bg-amber-50 text-amber-700',
            default => 'bg-emerald-50 text-emerald-700',
        };
    }
}
