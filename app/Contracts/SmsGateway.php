<?php

namespace App\Contracts;

interface SmsGateway
{
    /** Returns the provider's message reference. Throws on failure. */
    public function send(string $mobile, string $message): ?string;
}
