<?php

use App\Contracts\SmsGateway;
use App\Enums\DecisionType;
use App\Enums\ReviewOffice;
use App\Mail\AlertMessage;
use App\Models\Department;
use App\Models\OutboundMessage;
use App\Models\Staff;
use App\Services\ActivityLifecycle;
use App\Services\AppSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/** Records every SMS instead of sending it; fails when told to. */
function fakeSmsGateway(bool $fails = false): object
{
    $gateway = new class($fails) implements SmsGateway
    {
        /** @var list<array{0: string, 1: string}> */
        public array $sent = [];

        public function __construct(private bool $fails) {}

        public function send(string $mobile, string $message): ?string
        {
            if ($this->fails) {
                throw new RuntimeException('SMS not accepted: insufficient balance');
            }
            $this->sent[] = [$mobile, $message];

            return 'SL-'.count($this->sent);
        }
    };
    app()->instance(SmsGateway::class, $gateway);

    return $gateway;
}

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    $this->lifecycle = app(ActivityLifecycle::class);
    $this->hod = headOfDepartment(Department::query()->where('name', 'ICT')->firstOrFail());
    $this->acos = assistant();
    $this->acos->update(['phone' => '+254712000111']);
    $this->ceo = ceo();
    $this->memo = departmentMemo($this->hod);
});

it('emails and texts the reviewer when a memo is submitted, with the reference only', function () {
    $sms = fakeSmsGateway();

    $this->lifecycle->submitForReview($this->memo, $this->hod, ReviewOffice::AssistantChiefOfStaff);

    $reference = $this->memo->reference;
    expect($this->acos->notifications()->sole()->data['kind'])->toBe('submitted_for_review')
        ->and($sms->sent)->toBe([['+254712000111', "NG-CDF FAPM: Memo {$reference} has been submitted for your review. Sign in to act: ".route('activities.show', $this->memo)]]);
    Mail::assertSent(AlertMessage::class, fn (AlertMessage $mail) => $mail->hasTo($this->acos->email)
        && $mail->heading === "FAPM: Memo {$reference} to review"
        && str_contains($mail->text, 'Please review it, then forward it or return it for correction.')
        && ! str_contains($mail->text, $this->memo->title));
    expect(OutboundMessage::query()->pluck('status', 'channel')->all())->toBe(['mail' => 'sent', 'sms' => 'sent'])
        ->and(OutboundMessage::query()->where('channel', 'sms')->value('provider_reference'))->toBe('SL-1');
});

it('sends email and SMS only when the recipient must act, including the CEO when a decision is awaited', function () {
    fakeSmsGateway();
    $this->lifecycle->submitForReview($this->memo, $this->hod, ReviewOffice::AssistantChiefOfStaff);
    OutboundMessage::query()->each(fn (OutboundMessage $m) => $m->forceFill(['kind' => 'earlier'])->save());

    // Forwarding tells the Head of Department in the system only; the CEO, who must decide, is emailed and texted.
    $this->ceo->update(['phone' => '+254700000999']);
    $this->lifecycle->forward($this->memo->fresh(), $this->acos);
    expect($this->hod->notifications()->where('data->kind', 'memo_progressed')->count())->toBe(1)
        ->and($this->ceo->notifications()->where('data->kind', 'awaiting_decision')->count())->toBe(1)
        ->and(OutboundMessage::query()->where('kind', '!=', 'earlier')->get(['user_id', 'channel', 'recipient', 'subject'])->toArray())->toBe([
            ['user_id' => $this->ceo->id, 'channel' => 'mail', 'recipient' => $this->ceo->email, 'subject' => "FAPM: Memo {$this->memo->reference} awaiting your decision"],
            ['user_id' => $this->ceo->id, 'channel' => 'sms', 'recipient' => '+254700000999', 'subject' => null],
        ]);
    OutboundMessage::query()->each(fn (OutboundMessage $m) => $m->forceFill(['kind' => 'earlier'])->save());

    // The CEO's approval needs the Head of Department to act: it goes out to them, by email (no mobile on their account).
    $this->lifecycle->decide($this->memo->fresh(), DecisionType::Approved, null, $this->ceo);
    expect(OutboundMessage::query()->where('kind', '!=', 'earlier')->get(['user_id', 'channel', 'subject'])->toArray())->toBe([
        ['user_id' => $this->hod->id, 'channel' => 'mail', 'subject' => "FAPM: Memo {$this->memo->reference} approved"],
    ]);
});

it('texts the mobile number on the staff list when the account has none', function () {
    $sms = fakeSmsGateway();
    $officer = Staff::factory()->create(['phone' => '+254722333444']);
    $this->hod->update(['staff_id' => $officer->id]);
    $this->lifecycle->submitForReview($this->memo, $this->hod, ReviewOffice::AssistantChiefOfStaff);

    $this->lifecycle->returnForCorrection($this->memo->fresh(), 'Add the programme.', $this->acos);

    expect(collect($sms->sent)->pluck(0)->all())->toBe(['+254712000111', '+254722333444'])
        ->and($sms->sent[1][1])->toContain("Memo {$this->memo->reference} has been returned to you for correction.");
});

it('follows the Settings switches for email and SMS', function () {
    $sms = fakeSmsGateway();
    app(AppSettings::class)->set('alerts_sms', 0);

    $this->lifecycle->submitForReview($this->memo, $this->hod, ReviewOffice::AssistantChiefOfStaff);

    expect($sms->sent)->toBe([])
        ->and(OutboundMessage::query()->pluck('channel')->all())->toBe(['mail']);
});

it('marks a failed SMS on the log without undoing the action', function () {
    fakeSmsGateway(fails: true);

    $this->lifecycle->submitForReview($this->memo, $this->hod, ReviewOffice::AssistantChiefOfStaff);

    expect($this->memo->fresh()->status->value)->toBe('under_review')
        ->and(OutboundMessage::query()->where('channel', 'sms')->sole())
        ->status->toBe(OutboundMessage::FAILED)
        ->error->toBe('SMS not accepted: insufficient balance');
    expect(OutboundMessage::query()->where('channel', 'mail')->value('status'))->toBe(OutboundMessage::SENT);
});

it('heads the email as from the Office of the CEO when the CEO, the Chief of Staff or the Assistant acted, and not otherwise', function () {
    fakeSmsGateway();

    $this->actingAs($this->hod);
    $this->lifecycle->submitForReview($this->memo, $this->hod, ReviewOffice::AssistantChiefOfStaff);
    $this->actingAs($this->acos);
    $this->lifecycle->returnForCorrection($this->memo->fresh(), 'Add the programme.', $this->acos);

    expect(OutboundMessage::query()->where('channel', 'mail')->orderBy('id')->pluck('from_office', 'kind')->all())
        ->toBe(['submitted_for_review' => false, 'returned_for_correction' => true]);
    Mail::assertSent(AlertMessage::class, fn (AlertMessage $m) => $m->hasTo($this->hod->email) && $m->fromOffice
        && $m->hasFrom(config('mail.from.address'), 'Office of the Chief Executive Officer, NG-CDF Board')
        && str_contains($m->render(), 'OFFICE OF THE CHIEF EXECUTIVE OFFICER'));
    Mail::assertSent(AlertMessage::class, fn (AlertMessage $m) => $m->hasTo($this->acos->email) && ! $m->fromOffice
        && ! str_contains($m->render(), 'OFFICE OF THE CHIEF EXECUTIVE OFFICER'));
});
