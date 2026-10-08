<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class CloudflareTokenValidator
{
    public function validate(string $token, array $capabilities): bool
    {
        $client = $this->client($token);
        $verification = $client->get('https://api.cloudflare.com/client/v4/user/tokens/verify');

        if (! $verification->successful() || $verification->json('result.status') !== 'active') {
            return false;
        }

        if (in_array('dns', $capabilities, true)) {
            $zones = $client->get('https://api.cloudflare.com/client/v4/zones', ['per_page' => 1]);
            $zoneId = $zones->json('result.0.id');

            if (! $zones->successful() || ! is_string($zoneId)) {
                return false;
            }

            return $client->get("https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records", [
                'per_page' => 1,
            ])->successful();
        }

        return true;
    }

    private function client(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(10);
    }
}
