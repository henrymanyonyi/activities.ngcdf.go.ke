<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\SubmissionLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * A magic link a memo originator uses to submit activities without an account.
 * Create, edit and regenerate through App\Services\SubmissionLinkService.
 */
class SubmissionLink extends Model
{
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
    ];

    protected $hidden = ['token_hash', 'token_encrypted'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_opened_at' => 'datetime',
        ];
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
        return route('submit.show', ['token' => $this->plainToken()]);
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
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->isRevoked() => 'Revoked',
            $this->isExpired() => 'Expired',
            $this->isExhausted() => 'Used up',
            default => 'Active',
        };
    }

    public function statusBadgeClasses(): string
    {
        return match (true) {
            $this->isRevoked() => 'bg-red-50 text-red-700',
            $this->isExpired() => 'bg-slate-100 text-slate-500',
            $this->isExhausted() => 'bg-amber-50 text-amber-700',
            default => 'bg-emerald-50 text-emerald-700',
        };
    }
}
