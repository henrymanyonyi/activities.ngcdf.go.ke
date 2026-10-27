<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent to the account's own email when it is given access (made, reactivated or
 * given a role) or when the Chief of Staff emails a password link: a link to set
 * the password, so no one else ever learns it. It names the access only; the
 * details stay in OCEO. The link expires like any password reset link; after
 * that the officer uses "Forgot password?" on the sign-in page.
 */
class AccountAccessGranted extends ResetPassword
{
    public function __construct(#[\SensitiveParameter] $token, public ?string $access = null)
    {
        parent::__construct($token);
    }

    protected function buildMailMessage($url): MailMessage
    {
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('OCEO: set your password')
            ->line($this->access
                ? "You have been given access to the NG-CDF Office of the CEO system (OCEO) as {$this->access}."
                : 'A link to set your password for the NG-CDF Office of the CEO system (OCEO) has been sent to you.')
            ->line('Set your password to sign in. Your email address is your username.')
            ->action('Set your password', $url)
            ->line("The link expires in {$minutes} minutes. After that, use \"Forgot password?\" on the sign-in page.")
            ->line('If you did not expect this email, tell the Office of the CEO.');
    }
}
