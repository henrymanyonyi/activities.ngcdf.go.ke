<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** An action-needed email (App\Services\OutboundAlerts): the reference, the step and a sign-in link, nothing more. */
class AlertMessage extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $heading, public string $text, public string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.alert', text: 'emails.alert-text');
    }
}
