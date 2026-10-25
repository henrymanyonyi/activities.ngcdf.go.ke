<?php

use App\Contracts\SmsGateway;
use App\Enums\ActivityStatus;
use App\Enums\ParticipantRole;
use App\Enums\ReviewOffice;
use App\Exceptions\ActivityWorkflowException;
use App\Livewire\MemoRequest;
use App\Livewire\Memos\Show as MemoPage;
use App\Mail\AlertMessage;
use App\Models\ActivityCategory;
use App\Models\CostCategory;
use App\Models\County;
use App\Models\Department;
use App\Models\OutboundMessage;
use App\Models\Staff;
use App\Models\SubmissionLink;
use App\Services\ActivityEditor;
use App\Services\ActivityLifecycle;
use App\Services\DelegatedMemos;
use App\Services\MemoTracker;
use App\Services\SubmissionLinkService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/** Keeps every SMS sent during a delegated-memo test. */
function delegatedSmsOutbox(): object
{
    $outbox = new class implements SmsGateway
    {
        /** @var list<array{0: string, 1: string}> */
        public array $sent = [];

        public function send(string $mobile, string $message): ?string
        {
            $this->sent[] = [$mobile, $message];

            return null;
        }

        public function last(): string
        {
            return end($this->sent)[1] ?? '';
        }
    };
    app()->instance(SmsGateway::class, $outbox);

    return $outbox;
}

/** The six-digit code in the last SMS. */
function codeFrom(object $outbox): string
{
    preg_match('/code is (\d{6})/', $outbox->last(), $m);

    return $m[1];
}

beforeEach(function () {
    Storage::fake('local');
    $this->seed(SettingsSeeder::class);
    Mail::fake();
    $this->sms = delegatedSmsOutbox();
    $this->memos = app(DelegatedMemos::class);
    $this->ict = Department::query()->where('name', 'ICT')->firstOrFail();
    $this->hod = headOfDepartment($this->ict);
    $this->cos = chiefOfStaff();
    $this->officer = Staff::factory()->create(['name' => 'Kevin Preparer', 'email' => 'kevin.preparer@ngcdf.go.ke', 'phone' => '0722000333', 'department_id' => $this->ict->id]);
    $this->teamMate = Staff::factory()->create(['name' => 'Lucy Teammate', 'department_id' => $this->ict->id, 'job_grade' => '6']);
});

/** The officer opens the link and types the code they were sent. */
function openAsOfficer(object $test, SubmissionLink $link): void
{
    $test->memos->sendCode($link);
    $test->memos->verify($link->fresh(), codeFrom($test->sms));
}

/** @return array<string, mixed> */
function delegatedDetails(array $overrides = []): array
{
    $county = County::query()->where('name', 'Kisumu')->firstOrFail();

    return $overrides + [
        'title' => 'Network support visits, Kisumu County',
        'purpose' => 'Restore connectivity at the constituency offices.',
        'justification' => 'Four offices have been offline since October.',
        'activity_category_id' => ActivityCategory::query()->value('id'),
        'start_date' => today()->addDays(20)->toDateString(),
        'days' => 3,
        'county_id' => $county->id,
        'venue' => 'Kisumu Central NG-CDF office',
    ];
}

/** A memo the officer has prepared and submitted to the Head. */
function submittedDelegatedMemo(object $test): array
{
    [$link] = $test->memos->request($test->hod, $test->officer, 'The Kisumu network visits', 7);
    openAsOfficer($test, $link);
    $memo = $test->memos->saveDetails($link->fresh(), delegatedDetails());
    $test->memos->addOfficers($link->fresh(), [$test->teamMate->id], ParticipantRole::Lead);
    $test->memos->submit($link->fresh());

    return [$link->fresh(), $memo->fresh()];
}

it('sends the officer a secure link that opens only after a code sent to them', function () {
    [$link, $url] = $this->memos->request($this->hod, $this->officer, 'The Kisumu network visits', 7);
    $token = basename($url);

    expect($link)->kind->toBe(SubmissionLink::KIND_DELEGATED)->staff_id->toBe($this->officer->id)->department_id->toBe($this->ict->id)->created_by->toBe($this->hod->id)
        ->and($link->token_hash)->toBe(hash('sha256', $token))
        ->and(SubmissionLink::query()->where('token_hash', $token)->exists())->toBeFalse()
        ->and($link->expires_at->toDateString())->toBe(today()->addDays(7)->toDateString())
        ->and($this->sms->sent[0][0])->toBe('+254722000333')
        ->and($this->sms->sent[0][1])->toContain('The Head of ICT has asked you to prepare an activity memo')->toContain($url)->not->toContain('Kisumu');
    Mail::assertSent(AlertMessage::class, fn (AlertMessage $m) => $m->hasTo('kevin.preparer@ngcdf.go.ke') && ! $m->forAccount);

    // Before the code, the page shows nothing of the memo and nothing can be saved.
    Livewire::withCookie(config('privacy.cookie'), visitorPrivacyConsent())->test(MemoRequest::class, ['token' => $token])->assertSee('Confirm it is you')->assertDontSee('The Kisumu network visits');
    expect(fn () => $this->memos->saveDetails($link, delegatedDetails()))->toThrow(AuthorizationException::class);

    $this->memos->sendCode($link);
    expect(fn () => $this->memos->verify($link->fresh(), codeFrom($this->sms) === '000000' ? '111111' : '000000'))->toThrow(ActivityWorkflowException::class, 'not right')
        ->and($link->fresh()->otp_attempts)->toBe(1);
    $this->memos->verify($link->fresh(), codeFrom($this->sms));

    expect($this->memos->isVerified($link->fresh()))->toBeTrue()->and($link->fresh()->otp_hash)->toBeNull()
        ->and(OutboundMessage::query()->where('kind', 'memo_request_code')->pluck('body')->every(fn ($b) => str_contains($b, '******') && ! preg_match('/\b\d{6}\b/', $b)))->toBeTrue();
    session()->flush();
    expect($this->memos->isVerified($link->fresh()))->toBeFalse();
});

it('limits codes, wrong guesses and expired codes', function () {
    [$link] = $this->memos->request($this->hod, $this->officer, null, 7);

    $this->memos->sendCode($link);
    $this->memos->sendCode($link);
    $this->memos->sendCode($link);
    expect(fn () => $this->memos->sendCode($link))->toThrow(ActivityWorkflowException::class, 'Three codes have been sent');

    $link->forceFill(['otp_attempts' => DelegatedMemos::CODE_ATTEMPTS])->saveQuietly();
    expect(fn () => $this->memos->verify($link->fresh(), codeFrom($this->sms)))->toThrow(ActivityWorkflowException::class, 'Too many wrong codes');

    $link->forceFill(['otp_attempts' => 0, 'otp_expires_at' => now()->subMinute()])->saveQuietly();
    expect(fn () => $this->memos->verify($link->fresh(), codeFrom($this->sms)))->toThrow(ActivityWorkflowException::class, 'expired');
});

it('lets the officer prepare the memo for the Head, once, and submit it to them', function () {
    [$link] = $this->memos->request($this->hod, $this->officer, null, 7);
    openAsOfficer($this, $link);

    $memo = $this->memos->saveDetails($link->fresh(), delegatedDetails());
    $again = $this->memos->saveDetails($link->fresh(), delegatedDetails(['title' => 'Network support visits, Kisumu']));

    expect($again->id)->toBe($memo->id)
        ->and($memo->fresh())
        ->status->toBe(ActivityStatus::Draft)->title->toBe('Network support visits, Kisumu')
        ->organising_department_id->toBe($this->ict->id)->created_by->toBe($this->hod->id)->responsible_hod_id->toBe($this->hod->id)
        ->prepared_by_staff_id->toBe($this->officer->id)->submitted_by_name->toBe('Kevin Preparer')
        ->and($memo->locations()->sole()->venue)->toBe('Kisumu Central NG-CDF office');

    $this->memos->addOfficers($link->fresh(), [$this->teamMate->id], ParticipantRole::Lead);
    expect($memo->costs()->where('is_computed', true)->exists())->toBeTrue();

    // The Head cannot change it while the officer has it.
    expect(fn () => app(ActivityEditor::class)->update($memo->fresh(), ['title' => 'Mine now'], $this->hod))->toThrow(ActivityWorkflowException::class, 'Kevin Preparer is preparing this memo')
        ->and(fn () => app(ActivityLifecycle::class)->submitForReview($memo->fresh(), $this->hod, ReviewOffice::ChiefOfStaff))->toThrow(ActivityWorkflowException::class, 'still preparing');

    $this->memos->submit($link->fresh());

    expect($memo->fresh()->status)->toBe(ActivityStatus::WithHod)
        ->and($link->fresh()->isUsable())->toBeFalse()
        ->and($memo->statusHistory()->reorder('id', 'desc')->first())->actor_name->toBe('Kevin Preparer')->user_id->toBeNull()
        ->and($this->hod->notifications()->where('data->kind', 'prepared_for_hod')->count())->toBe(1)
        ->and(OutboundMessage::query()->where('user_id', $this->hod->id)->where('kind', 'prepared_for_hod')->exists())->toBeTrue()
        ->and(fn () => $this->memos->submit($link->fresh()))->toThrow(ActivityWorkflowException::class, 'submitted to the Head');
    expect(app(MemoTracker::class)->query($this->hod, 'from_staff')->pluck('id')->all())->toBe([$memo->id])
        ->and(app(MemoTracker::class)->actionCount($this->hod))->toBe(1);
});

it('lets the Head return the memo to the officer with comments, who corrects and resubmits it through a new link', function () {
    [$oldLink, $memo] = submittedDelegatedMemo($this);

    $this->memos->returnToPreparer($memo, 'Add the programme for each day.', $this->hod);

    $newLink = SubmissionLink::query()->delegated()->where('activity_id', $memo->id)->whereNull('revoked_at')->whereNull('completed_at')->sole();
    expect($memo->fresh())->status->toBe(ActivityStatus::ReturnedToPreparer)->status_reason->toBe('Add the programme for each day.')
        ->and($newLink->id)->not->toBe($oldLink->id)
        ->and($this->sms->last())->toContain("returned memo {$memo->reference} to you")->not->toContain('programme');

    openAsOfficer($this, $newLink);
    Livewire::withCookie(config('privacy.cookie'), visitorPrivacyConsent())->test(MemoRequest::class, ['token' => $newLink->plainToken()])->assertSee('Add the programme for each day.')->assertSet('title', 'Network support visits, Kisumu County');
    $this->memos->saveDetails($newLink->fresh(), delegatedDetails(['notes' => 'Day 1 Kisumu Central; day 2 Kisumu East; day 3 Kisumu West.']));
    $this->memos->submit($newLink->fresh());

    expect($memo->fresh()->status)->toBe(ActivityStatus::WithHod)
        ->and($memo->statusHistory()->pluck('to_status')->map->value->all())->toBe(['draft', 'with_hod', 'returned_to_preparer', 'with_hod']);
});

it('lets the Head decline the memo with a reason, keeping it out of the Office of the CEO\'s declined memos', function () {
    [, $memo] = submittedDelegatedMemo($this);

    expect(fn () => $this->memos->decline($memo, ' ', $this->hod))->toThrow(ActivityWorkflowException::class, 'reason');
    $this->memos->decline($memo, 'Covered by the Q3 maintenance contract.', $this->hod);

    expect($memo->fresh())->status->toBe(ActivityStatus::Declined)->status_reason->toBe('Covered by the Q3 maintenance contract.')
        ->and($memo->fresh()->wasDeclinedByHod())->toBeTrue()
        ->and(app(MemoTracker::class)->query($this->hod, 'declined')->pluck('id')->all())->toBe([$memo->id])
        ->and(app(MemoTracker::class)->query(ceo(), 'declined')->count())->toBe(0)
        ->and($this->sms->last())->toContain("declined memo {$memo->reference}")->not->toContain('Q3');
});

it('lets the Head forward the memo to the Chief of Staff, into the existing review and approval', function () {
    [, $memo] = submittedDelegatedMemo($this);

    Livewire::actingAs($this->hod)->test(MemoPage::class, ['activity' => $memo])
        ->assertSee('Prepared by')->assertSee('Forward to the Chief of Staff')
        ->call('openHodAction', 'showForward')->set('note', 'Reviewed; please proceed.')
        ->call('forwardToChiefOfStaff')->assertHasNoErrors();

    $memo = $memo->fresh();
    expect($memo)->status->toBe(ActivityStatus::UnderReview)->review_office->toBe(ReviewOffice::ChiefOfStaff)
        ->and($memo->statusHistory()->reorder('id', 'desc')->first())->user_id->toBe($this->hod->id)->reason->toContain('Reviewed by the Head of Department and forwarded, on behalf of Kevin Preparer, for review by the Chief of Staff')
        ->and($this->cos->notifications()->where('data->kind', 'submitted_for_review')->count())->toBe(1);

    app(ActivityLifecycle::class)->forward($memo, $this->cos);
    expect($memo->fresh()->status)->toBe(ActivityStatus::AwaitingDecision);
});

it('never lets a link be used to act as the Head, for another department or after it ends', function () {
    [$link, $url] = $this->memos->request($this->hod, $this->officer, null, 7);
    $token = basename($url);
    $otherHead = headOfDepartment(Department::query()->where('name', 'Supply Chain')->firstOrFail());

    // Only the Head of the department, and only their own links.
    expect(fn () => $this->memos->request($this->cos, $this->officer, null, 7))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->memos->revoke($link, $otherHead))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->memos->request($this->hod, $this->officer, null, 45))->toThrow(ActivityWorkflowException::class, 'from 1 to 30 days')
        ->and(app(SubmissionLinkService::class)->find($token))->toBeNull();

    // The link opens nothing of the officer's account or the Head's: no session, no other memos.
    $this->get(route('memo-request.show', $token))->assertRedirect(route('privacy.statement'));
    $this->withCookie(config('privacy.cookie'), visitorPrivacyConsent())->get(route('memo-request.show', $token))->assertOk()->assertSee('Confirm it is you');
    $this->assertGuest();
    $this->get(route('memos.index'))->assertRedirect();

    // Revoked or expired, it stops at once.
    $this->memos->revoke($link, $this->hod);
    expect(fn () => $this->memos->sendCode($link->fresh()))->toThrow(ActivityWorkflowException::class, 'withdrawn');
    [$expiring] = $this->memos->request($this->hod, $this->officer, null, 1);
    $expiring->forceFill(['expires_at' => now()->subMinute()])->saveQuietly();
    expect(fn () => $this->memos->sendCode($expiring->fresh()))->toThrow(ActivityWorkflowException::class, 'expired');

    // If the Head who sent it no longer heads the department, the link cannot be used.
    [$link2] = $this->memos->request($this->hod, $this->officer, null, 7);
    openAsOfficer($this, $link2);
    $this->hod->update(['is_active' => false]);
    expect(fn () => $this->memos->saveDetails($link2->fresh(), delegatedDetails()))->toThrow(ActivityWorkflowException::class, 'no longer heads the department');
});

it('takes the officer through the memo page: code, details, team, cost and submission', function () {
    [, $url] = $this->memos->request($this->hod, $this->officer, 'The Kisumu network visits', 7);
    $details = delegatedDetails();
    $page = Livewire::withCookie(config('privacy.cookie'), visitorPrivacyConsent())->test(MemoRequest::class, ['token' => basename($url)])
        ->call('sendCode')->assertSee('Code sent to');

    $page->set('code', codeFrom($this->sms))->call('verify')->assertHasNoErrors()
        ->assertSee('The Kisumu network visits')
        ->set('title', $details['title'])->set('purpose', $details['purpose'])->set('justification', $details['justification'])
        ->set('activity_category_id', (string) $details['activity_category_id'])->set('start_date', $details['start_date'])->set('days', 3)
        ->set('county_id', (string) $details['county_id'])->set('venue', $details['venue'])
        ->call('save')->assertHasNoErrors()
        ->assertSee('2. The team')
        ->set('staffSearch', 'Lucy')->assertSee('Lucy Teammate')
        ->set('picked', [$this->teamMate->id])->call('addOfficers')->assertHasNoErrors()
        ->set('costCategory', (string) CostCategory::query()->where('code', 'fuel')->value('id'))->set('costAmount', '8,000')->call('addCost')->assertHasNoErrors()
        ->assertSee('Preview the memo')
        ->assertSee('DSA 2 nights')
        // Changing the days saves at once and recalculates the allowances.
        ->set('days', 5)->assertHasNoErrors()->assertSee('DSA 4 nights')
        ->set('confirmSubmit', true)->call('submit')->assertSee('Submitted');

    $memo = SubmissionLink::query()->delegated()->sole()->memo;
    expect($memo->status)->toBe(ActivityStatus::WithHod)->and($memo->participants()->count())->toBe(1)
        ->and($memo->costs()->whereHas('category', fn ($q) => $q->where('code', 'fuel'))->value('estimated_amount'))->toBe('8000.00');
});
