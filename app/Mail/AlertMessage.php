<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** An action-needed email (App\Services\OutboundAlerts): the reference, the step and a sign-in link, nothing more. */
class AlertMessage extends Mailable
{
    use Queueable, SerializesModels;

    public const OFFICE = 'Office of the Chief Executive Officer';

    /**
     * $forAccount: false for an officer without an account (a delegated memo link), who opens the link instead of signing in.
     * $fromOffice: the CEO, the Chief of Staff or the Assistant took the action, so it comes from the Office of the CEO.
     */
    public function __construct(public string $heading, public string $text, public string $link, public bool $forAccount = true, public bool $fromOffice = false) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->fromOffice ? new Address((string) config('mail.from.address'), self::OFFICE.', NG-CDF Board') : null,
            subject: $this->heading,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.alert', text: 'emails.alert-text');
    }
}
