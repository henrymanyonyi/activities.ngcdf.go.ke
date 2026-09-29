<?php

namespace App\Services;

use App\Models\SubmissionLink;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates, edits and resolves magic submission links. The token is looked up
 * by SHA-256 hash; the encrypted copy exists only so CEO/Admin can copy the
 * link again. Regenerating issues a new token and kills the old URL.
 */
class SubmissionLinkService
{
    private const TOKEN_LENGTH = 48;

    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: SubmissionLink, 1: string} the link and its plain token
     */
    public function create(array $attributes, User $creator): array
    {
        $token = $this->newToken();

        $link = SubmissionLink::create($this->editable($attributes) + [
            'token_hash' => SubmissionLink::hashToken($token),
            'token_encrypted' => Crypt::encryptString($token),
            'created_by' => $creator->id,
        ]);

        return [$link, $token];
    }

    /** @param  array<string, mixed>  $attributes */
    public function update(SubmissionLink $link, array $attributes): SubmissionLink
    {
        $link->update($this->editable($attributes));

        return $link;
    }

    /** Issue a new token; the previous URL stops working immediately. */
    public function regenerate(SubmissionLink $link): string
    {
        $token = $this->newToken();

        $link->forceFill([
            'token_hash' => SubmissionLink::hashToken($token),
            'token_encrypted' => Crypt::encryptString($token),
        ])->saveQuietly();

        $this->audit->record($link, 'token_regenerated');

        return $token;
    }

    public function revoke(SubmissionLink $link): void
    {
        $link->update(['revoked_at' => now()]);
    }

    public function reactivate(SubmissionLink $link): void
    {
        $link->update(['revoked_at' => null]);
    }

    /** Resolve a token to its link, or null if unknown. Expiry/revocation is checked by the caller. */
    public function find(string $token): ?SubmissionLink
    {
        if (strlen($token) !== self::TOKEN_LENGTH || ! ctype_alnum($token)) {
            return null;
        }

        return SubmissionLink::query()->where('token_hash', SubmissionLink::hashToken($token))->first();
    }

    public function recordOpen(SubmissionLink $link): void
    {
        $link->forceFill(['last_opened_at' => now(), 'open_count' => $link->open_count + 1])->saveQuietly();
        $this->audit->record($link, 'opened', actorName: $link->recipient_name);
    }

    /**
     * Count one new submission against the link's cap, under a row lock so two
     * simultaneous submissions cannot both take the last slot.
     */
    public function consumeSubmission(SubmissionLink $link): bool
    {
        return DB::transaction(function () use ($link) {
            $fresh = SubmissionLink::query()->lockForUpdate()->find($link->id);
            if (! $fresh || ! $fresh->acceptsSubmissions()) {
                return false;
            }

            $fresh->forceFill(['submissions_count' => $fresh->submissions_count + 1])->saveQuietly();
            $link->submissions_count = $fresh->submissions_count;

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function editable(array $attributes): array
    {
        return collect($attributes)->only([
            'label', 'recipient_name', 'recipient_email', 'recipient_phone', 'department_id',
            'activity_category_id', 'instructions', 'expires_at', 'max_submissions',
        ])->map(fn ($value) => $value === '' ? null : $value)->all();
    }

    private function newToken(): string
    {
        return Str::random(self::TOKEN_LENGTH);
    }
}
