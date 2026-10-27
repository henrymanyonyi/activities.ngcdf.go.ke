<?php

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Enums\ParticipantRole;
use App\Enums\ReviewOffice;
use App\Exceptions\ActivityWorkflowException;
use App\Models\Activity;
use App\Models\ActivityDocument;
use App\Models\ActivityLocation;
use App\Models\Constituency;
use App\Models\County;
use App\Models\Staff;
use App\Models\SubmissionLink;
use App\Models\User;
use App\Support\MobileNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Delegated activity memos (25 October 2026). A Head of Department asks one
 * officer on the staff list to prepare a memo, through a secure, time-limited
 * link sent to that officer's email and mobile on the staff list:
 *
 *  - The token is random (48 characters), stored only as a SHA-256 hash
 *    (plus an encrypted copy so the Head can copy it again), and opens one
 *    memo for one officer in the Head's department. It opens only after a
 *    six-digit code sent to the officer's email or mobile, valid 10 minutes,
 *    five tries, three codes per 10 minutes; the browser session is then
 *    trusted for two hours. The officer never has an account: the link opens
 *    that memo and nothing else, and every change is audited under their name.
 *  - The memo is an ordinary department memo (created_by and responsible
 *    Head: the Head who asked; prepared_by_staff_id: the officer), built with
 *    the same rules through ActivityEditor::asPreparer(): the memo form's
 *    fields, a location, the team (availability checked, no override), cost
 *    lines and supporting documents; DSA is calculated as usual.
 *  - The officer submits it to the Head (With the Head of Department), and
 *    the link stops opening it. The Head returns it to the officer with
 *    comments (a new link goes to them), declines it with a reason, or
 *    forwards it to the Chief of Staff, where the existing review and
 *    approval continue unchanged.
 *  - Every message to the officer gives no memo details (OutboundAlerts::toContact).
 */
class DelegatedMemos
{
    public const MAX_DAYS = 30;

    public const CODE_MINUTES = 10;

    public const CODE_ATTEMPTS = 5;

    public const TRUSTED_HOURS = 2;

    /** The memo fields an officer fills in, as on the memo form. */
    public const FIELDS = [
        'title', 'purpose', 'expected_outputs', 'justification', 'risks', 'target_audience', 'notes',
        'activity_category_id', 'activity_type_id', 'start_date', 'days', 'requested_budget',
    ];

    public function __construct(
        private SubmissionLinkService $links,
        private ActivityEditor $editor,
        private ActivityLifecycle $lifecycle,
        private DocumentStore $documents,
        private OutboundAlerts $outbound,
        private AuditLogger $audit,
    ) {}

    // ── The Head of Department ───────────────────────────────────────

    /**
     * Asks an officer to prepare a memo, or (for a memo returned to them) to correct it, and sends them the link.
     *
     * @return array{0: SubmissionLink, 1: string} the link and its URL
     */
    public function request(User $hod, Staff $staff, ?string $purpose, int $days, ?Activity $memo = null): array
    {
        $this->assertHead($hod);
        if (! $staff->is_active) {
            throw new ActivityWorkflowException("{$staff->name} is not active on the staff list.");
        }
        if (blank($staff->email) && ! MobileNumber::isValid($staff->phone)) {
            throw new ActivityWorkflowException("{$staff->name} has no email or mobile number on the staff list, so the link and its code cannot reach them. Add one first.");
        }
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new ActivityWorkflowException('The link can last from 1 to '.self::MAX_DAYS.' days.');
        }
        if ($memo && ($memo->status !== ActivityStatus::ReturnedToPreparer || (int) $memo->organising_department_id !== (int) $hod->department_id)) {
            throw new ActivityWorkflowException('A new link for an existing memo can be sent only while it is returned to the officer.');
        }

        [$link, $token] = DB::transaction(function () use ($hod, $staff, $purpose, $days, $memo) {
            if ($memo) {
                // One way in at a time: the earlier links to this memo stop working.
                SubmissionLink::query()->delegated()->where('activity_id', $memo->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            }

            return $this->links->create([
                'kind' => SubmissionLink::KIND_DELEGATED,
                'label' => 'Memo request for '.$staff->name,
                'recipient_name' => $staff->name,
                'recipient_email' => $staff->email,
                'recipient_phone' => MobileNumber::normalise($staff->phone),
                'department_id' => $hod->department_id,
                'staff_id' => $staff->id,
                'activity_id' => $memo?->id,
                'instructions' => filled($purpose) ? trim((string) $purpose) : null,
                'expires_at' => now()->addDays($days)->endOfDay(),
                'max_submissions' => 1,
            ], $hod);
        });

        $url = route('memo-request.show', ['token' => $token]);
        $department = $hod->department?->name ?? 'your department';
        $this->outbound->toContact($staff->name, $staff->email, $staff->phone, $memo ? 'memo_request_returned' : 'memo_request', $memo ? [
            'subject' => "Memo {$memo->reference} returned to you",
            'line' => "The Head of {$department} has returned memo {$memo->reference} to you with comments.",
            'todo' => 'Open the link, make the changes asked for and submit it again. It works until '.$link->expires_at->format('d M Y').'.',
        ] : [
            'subject' => 'Please prepare an activity memo',
            'line' => "The Head of {$department} has asked you to prepare an activity memo in OCEO.",
            'todo' => 'Open the link to start. It asks for a code sent to you, and works until '.$link->expires_at->format('d M Y').'.',
        ], $url, $memo?->id);

        return [$link, $url];
    }

    public function revoke(SubmissionLink $link, User $hod): void
    {
        $this->assertHead($hod);
        if (! $link->isDelegated() || (int) $link->department_id !== (int) $hod->department_id) {
            throw new AuthorizationException('This link belongs to another department.');
        }
        if ($link->isCompleted()) {
            throw new ActivityWorkflowException('The memo has already been submitted to you; the link no longer opens it.');
        }

        $link->update(['revoked_at' => now()]);
    }

    /** @return Collection<int, SubmissionLink> the department's memo requests, newest first */
    public function linksFor(User $hod): Collection
    {
        return SubmissionLink::query()->delegated()->where('department_id', $hod->department_id)
            ->with(['staff', 'memo', 'creator'])->latest('id')->limit(50)->get();
    }

    /** The Head sends the memo back to the officer with comments; a new link goes to them. */
    public function returnToPreparer(Activity $memo, string $reason, User $hod, int $days = 7): void
    {
        DB::transaction(function () use ($memo, $reason, $hod, $days) {
            $this->lifecycle->returnToPreparer($this->locked($memo), $reason, $hod);
            $staff = $memo->preparedBy()->first() ?? throw new ActivityWorkflowException('The officer who prepared this memo is no longer on the staff list.');
            $this->request($hod, $staff, null, $days, $memo->fresh());
        });
    }

    public function decline(Activity $memo, string $reason, User $hod): void
    {
        DB::transaction(function () use ($memo, $reason, $hod) {
            $this->lifecycle->declineByHod($this->locked($memo), $reason, $hod);
            $this->tellPreparer($memo, 'memo_request_declined', [
                'subject' => "Memo {$memo->reference} declined",
                'line' => "The Head of Department has declined memo {$memo->reference}, which you prepared.",
                'todo' => 'Your Head of Department will tell you why. Nothing more is needed from you.',
            ]);
        });
    }

    /**
     * The Head forwards the memo, on the officer's behalf, for review by the Assistant or the Chief of Staff;
     * the existing review and the CEO's decision follow. $acting: the Head signs as acting.
     */
    public function forward(Activity $memo, User $hod, ReviewOffice $office = ReviewOffice::ChiefOfStaff, ?string $note = null, bool $acting = false): void
    {
        DB::transaction(function () use ($memo, $hod, $office, $note, $acting) {
            $locked = $this->locked($memo);
            if ($locked->status !== ActivityStatus::WithHod) {
                throw new ActivityWorkflowException('Only a memo with you for review can be forwarded.');
            }
            $this->lifecycle->submitForReview($locked, $hod, $office, $note, $acting);
            $this->tellPreparer($memo, 'memo_request_forwarded', [
                'subject' => "Memo {$memo->reference} forwarded",
                'line' => "The Head of Department has forwarded memo {$memo->reference}, which you prepared, to the Office of the CEO.",
                'todo' => 'Nothing more is needed from you.',
            ]);
        });
    }

    // ── The officer, through the link ────────────────────────────────

    public function find(string $token): ?SubmissionLink
    {
        return $this->links->find($token, SubmissionLink::KIND_DELEGATED);
    }

    /**
     * Sends a six-digit code to the officer's email and mobile on the staff list.
     *
     * @return list<string> where it went, masked
     */
    public function sendCode(SubmissionLink $link): array
    {
        $this->assertOpen($link);
        $key = 'memo-request-code:'.$link->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw new ActivityWorkflowException('Three codes have been sent. Wait '.ceil(RateLimiter::availableIn($key) / 60).' minutes before asking for another.');
        }
        RateLimiter::hit($key, 600);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $link->forceFill(['otp_hash' => $this->codeHash($link, $code), 'otp_expires_at' => now()->addMinutes(self::CODE_MINUTES), 'otp_attempts' => 0])->saveQuietly();

        [$email, $mobile] = $this->contact($link);
        $this->outbound->toContact($link->recipient_name, $email, $mobile, 'memo_request_code', [
            'subject' => 'Your code to open the memo request',
            'line' => "Your code is {$code}. It works for ".self::CODE_MINUTES.' minutes.',
            'todo' => 'Type it on the memo request page. If you did not ask for it, ignore this message.',
        ], route('memo-request.show', ['token' => $link->plainToken()]));
        $this->audit->record($link, 'code_sent', actorName: $link->recipient_name);

        return array_values(array_filter([
            $email ? preg_replace('/(?<=.).(?=[^@]*@)/', '*', $email) : null,
            $mobile ? MobileNumber::mask((string) MobileNumber::normalise($mobile)) : null,
        ]));
    }

    /** Checks the code and trusts this browser session for the link for two hours. */
    public function verify(SubmissionLink $link, string $code): void
    {
        $this->assertOpen($link);

        $right = DB::transaction(function () use ($link, $code) {
            $fresh = SubmissionLink::query()->lockForUpdate()->findOrFail($link->id);
            if (! $fresh->otp_hash || $fresh->otp_expires_at?->isPast()) {
                throw new ActivityWorkflowException('The code has expired. Ask for a new one.');
            }
            if ($fresh->otp_attempts >= self::CODE_ATTEMPTS) {
                throw new ActivityWorkflowException('Too many wrong codes. Ask for a new one.');
            }
            if (! hash_equals($fresh->otp_hash, $this->codeHash($fresh, trim($code)))) {
                // Counted (and committed) even though the attempt fails, so guessing runs out.
                $fresh->forceFill(['otp_attempts' => $fresh->otp_attempts + 1])->saveQuietly();

                return false;
            }

            $fresh->forceFill(['otp_hash' => null, 'otp_expires_at' => null, 'otp_attempts' => 0, 'verified_at' => now()])->saveQuietly();
            $this->audit->record($fresh, 'verified', actorName: $fresh->recipient_name);

            return true;
        });
        if (! $right) {
            throw new ActivityWorkflowException('That code is not right. Check it and try again.');
        }

        session()->put($this->sessionKey($link), ['hash' => $link->token_hash, 'until' => now()->addHours(self::TRUSTED_HOURS)->timestamp]);
        $this->links->recordOpen($link);
    }

    public function isVerified(SubmissionLink $link): bool
    {
        $trusted = session($this->sessionKey($link));

        return is_array($trusted) && hash_equals((string) $link->token_hash, (string) ($trusted['hash'] ?? '')) && ($trusted['until'] ?? 0) > now()->timestamp;
    }

    /**
     * Only the dates, saved as soon as the officer changes them, so the allowances (DSA) and the total follow at once.
     */
    public function saveDates(SubmissionLink $link, string $startDate, int $days): Activity
    {
        $memo = $this->editableMemo($link);
        $this->editor->asPreparer($memo, $link->recipient_name, fn () => $this->editor->update($memo, ['start_date' => $startDate, 'days' => $days], $this->head($link)));

        return $memo->fresh();
    }

    /**
     * The memo's details, as on the memo form, and its location. The first save creates the memo, in the
     * Head's department, as theirs, prepared by the officer; a repeated save (a refresh, a double click)
     * changes that same memo, never a second one.
     *
     * @param  array<string, mixed>  $data  the FIELDS plus county_id, constituency_id, venue
     */
    public function saveDetails(SubmissionLink $link, array $data): Activity
    {
        return DB::transaction(function () use ($link, $data) {
            $link = SubmissionLink::query()->lockForUpdate()->findOrFail($link->id);
            $memo = $this->editableMemo($link, allowNew: true);
            $hod = $this->head($link);
            $attributes = collect($data)->only(self::FIELDS)->map(fn ($v) => $v === '' ? null : $v)->all();

            $memo = $memo
                ? $this->editor->asPreparer($memo, $link->recipient_name, fn () => $this->editor->update($memo, $attributes, $hod))
                : $this->create($link, $hod, $attributes);

            $this->editor->asPreparer($memo, $link->recipient_name, fn () => $this->saveLocation($memo, $data, $hod));

            return $memo->fresh();
        });
    }

    /** @return list<string> warnings, such as an officer near their field-day limit */
    public function addOfficers(SubmissionLink $link, array $staffIds, ParticipantRole $role): array
    {
        $memo = $this->editableMemo($link);

        return $this->editor->asPreparer($memo, $link->recipient_name, fn () => $this->editor->addStaff($memo, array_map('intval', $staffIds), $role, $this->head($link))['warnings']);
    }

    public function removeParticipant(SubmissionLink $link, int $participantId): void
    {
        $memo = $this->editableMemo($link);
        $participant = $memo->participants()->findOrFail($participantId);
        $this->editor->asPreparer($memo, $link->recipient_name, fn () => $this->editor->removeParticipant($participant, $this->head($link)));
    }

    /** @param  array{cost_category_id: int|string, description: ?string, estimated_amount: string, activity_participant_id?: int|string|null}  $data */
    public function saveCost(SubmissionLink $link, array $data): void
    {
        $memo = $this->editableMemo($link);
        $this->editor->asPreparer($memo, $link->recipient_name, fn () => $this->editor->savePlannedCost($memo, $data, $this->head($link)));
    }

    public function removeCost(SubmissionLink $link, int $costId): void
    {
        $memo = $this->editableMemo($link);
        $line = $memo->costs()->where('is_computed', false)->findOrFail($costId);
        $this->editor->asPreparer($memo, $link->recipient_name, fn () => $this->editor->removePlannedCost($line, $this->head($link)));
    }

    public function attach(SubmissionLink $link, UploadedFile $file): void
    {
        $memo = $this->editableMemo($link);
        AuditLogger::actingAs($link->recipient_name, fn () => $this->documents->store($memo, $file, ActivityDocument::KIND_OTHER, link: $link));
    }

    /** Submits the memo to the Head of Department; the link no longer opens it for editing. */
    public function submit(SubmissionLink $link): void
    {
        DB::transaction(function () use ($link) {
            $link = SubmissionLink::query()->lockForUpdate()->findOrFail($link->id);
            $memo = $this->editableMemo($link);
            AuditLogger::actingAs($link->recipient_name, fn () => $this->lifecycle->submitToHod($memo, $link->recipient_name));
            $link->forceFill(['completed_at' => now(), 'submissions_count' => $link->submissions_count + 1])->saveQuietly();
        });
    }

    /** The memo the link opens, if it may be changed now. */
    public function editableMemo(SubmissionLink $link, bool $allowNew = false): ?Activity
    {
        $this->assertOpen($link);
        if (! $this->isVerified($link)) {
            throw new AuthorizationException('Confirm it is you with the code first.');
        }

        $memo = $link->activity_id ? Activity::query()->find($link->activity_id) : null;
        if (! $memo && ! $allowNew) {
            throw new ActivityWorkflowException('Save the memo details first.');
        }
        if ($memo && ! $memo->isWithPreparer()) {
            throw new ActivityWorkflowException('This memo has been submitted to the Head of Department and can no longer be changed here.');
        }

        return $memo;
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function create(SubmissionLink $link, User $hod, array $attributes): Activity
    {
        $memo = AuditLogger::actingAs($link->recipient_name, fn () => $this->editor->create($attributes + [
            'submission_link_id' => $link->id,
            'prepared_by_staff_id' => $link->staff_id,
            'submitted_by_name' => $link->recipient_name,
            'submitted_by_email' => $link->recipient_email,
            'submitted_by_phone' => $link->recipient_phone,
        ], $hod, $link->recipient_name));
        $link->forceFill(['activity_id' => $memo->id])->saveQuietly();

        return $memo;
    }

    /** One location: the county (for DSA), constituency and venue. */
    private function saveLocation(Activity $memo, array $data, User $hod): void
    {
        $county = filled($data['county_id'] ?? null) ? County::query()->find($data['county_id']) : null;
        $constituency = filled($data['constituency_id'] ?? null) ? Constituency::query()->find($data['constituency_id']) : null;
        $venue = filled($data['venue'] ?? null) ? trim((string) $data['venue']) : null;
        $wanted = ['county_id' => $county?->id, 'constituency_id' => $constituency?->id, 'venue' => $venue];
        $current = $memo->locations()->first();

        if ($current && $current->only(['county_id', 'constituency_id', 'venue']) == $wanted && $memo->locations()->count() === 1) {
            return;
        }

        $memo->locations()->get()->each(fn (ActivityLocation $l) => $this->editor->removeLocation($l, $hod));
        if ($county || $venue) {
            $this->editor->addLocation($memo, $wanted + ['region_id' => $constituency?->region_id ?? $county?->constituencies()->value('region_id')], $hod);
        }
    }

    private function head(SubmissionLink $link): User
    {
        $hod = User::query()->find($link->created_by);
        if (! $hod?->is_active || ! $hod->can('memos.originate') || (int) $hod->department_id !== (int) $link->department_id) {
            throw new ActivityWorkflowException('The Head of Department who sent this link no longer heads the department, so it cannot be used. Ask your Head of Department for a new one.');
        }

        return $hod;
    }

    private function assertHead(User $user): void
    {
        if (! $user->isHeadOfDepartment() || ! $user->can('memos.originate') || ! isset($user->department_id)) {
            throw new AuthorizationException('Only a Head of Department asks an officer to prepare a memo.');
        }
    }

    private function assertOpen(SubmissionLink $link): void
    {
        if (! $link->isDelegated() || ! $link->isUsable()) {
            throw new ActivityWorkflowException(match (true) {
                $link->isCompleted() => 'This memo has been submitted to the Head of Department. The link no longer opens it.',
                $link->isRevoked() => 'This link has been withdrawn. Ask your Head of Department for a new one.',
                default => 'This link has expired. Ask your Head of Department for a new one.',
            });
        }
    }

    private function locked(Activity $memo): Activity
    {
        $locked = Activity::query()->lockForUpdate()->findOrFail($memo->id);
        if (! $locked->isDelegated()) {
            throw new ActivityWorkflowException('This memo was not prepared through a memo request.');
        }

        return $locked;
    }

    /** @param  array{subject: string, line: string, todo: string}  $text */
    private function tellPreparer(Activity $memo, string $kind, array $text): void
    {
        $staff = $memo->preparedBy()->first();
        $this->outbound->toContact($memo->submitted_by_name ?? 'colleague', $staff?->email ?? $memo->submitted_by_email, $staff?->phone ?? $memo->submitted_by_phone, $kind, $text, null, $memo->id);
    }

    /** @return array{0: ?string, 1: ?string} the officer's email and mobile now on the staff list, else as on the link */
    private function contact(SubmissionLink $link): array
    {
        $staff = $link->staff;

        return [$staff?->email ?: $link->recipient_email, $staff?->phone ?: $link->recipient_phone];
    }

    private function codeHash(SubmissionLink $link, string $code): string
    {
        return hash_hmac('sha256', $link->id.'|'.$code, (string) config('app.key'));
    }

    private function sessionKey(SubmissionLink $link): string
    {
        return 'memo_request.'.$link->id;
    }
}
