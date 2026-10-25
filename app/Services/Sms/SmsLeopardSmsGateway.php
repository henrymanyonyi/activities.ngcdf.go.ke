<?php

namespace App\Services\Sms;

use App\Contracts\SmsGateway;
use App\Support\MobileNumber;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * SMS Leopard API, as NG-CDF Careers uses it (Basic auth with the API key and
 * secret): GET /sms/send with message, destination and source as query
 * parameters. Sends from the sender ID assigned to the Board's account.
 */
class SmsLeopardSmsGateway implements SmsGateway
{
    /** @param  array{api_key: ?string, api_secret: ?string, sender_id: ?string, base_url: string}  $config */
    public function __construct(private array $config) {}

    public function send(string $mobile, string $message): ?string
    {
        if (blank($this->config['api_key']) || blank($this->config['api_secret'])) {
            throw new RuntimeException('SMS Leopard is not configured: set SMSLEOPARD_API_KEY and SMSLEOPARD_API_SECRET.');
        }

        $response = $this->client()->get($this->url('/sms/send'), [
            'message' => $message,
            'destination' => self::destination($mobile),
            'source' => $this->config['sender_id'] ?: 'NG-CDFBOARD',
        ]);

        if (! $response->successful() || $response->json('success') !== true) {
            Log::error('SMS Leopard send failed', ['status' => $response->status(), 'body' => $response->body()]);

            throw new RuntimeException('SMS not accepted: '.($response->json('message') ?: $response->status()));
        }

        return $response->json('recipients.0.id');
    }

    /** +254712345678 to 254712345678: the country code without the plus, as the destination parameter requires. */
    public static function destination(string $mobile): string
    {
        return ltrim(MobileNumber::normalise($mobile) ?? $mobile, '+');
    }

    private function client(): PendingRequest
    {
        return Http::withBasicAuth((string) $this->config['api_key'], (string) $this->config['api_secret'])
            ->acceptJson()
            ->timeout(15);
    }

    private function url(string $path): string
    {
        return rtrim($this->config['base_url'], '/').$path;
    }
}
