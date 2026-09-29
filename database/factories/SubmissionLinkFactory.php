<?php

namespace Database\Factories;

use App\Models\SubmissionLink;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubmissionLink>
 */
class SubmissionLinkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = Str::random(48);

        return [
            'token_hash' => SubmissionLink::hashToken($token),
            'token_encrypted' => Crypt::encryptString($token),
            'label' => 'Q2 field plan',
            'recipient_name' => fake()->name(),
            'expires_at' => now()->addDays(14),
        ];
    }
}
