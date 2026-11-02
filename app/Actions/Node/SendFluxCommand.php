<?php

namespace App\Actions\Node;

use Illuminate\Support\Facades\Http;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Sends a direct (not journaled) command through the Flux internal API. A rejected command
 * throws a RuntimeException with the message of Sentinel and the HTTP status as its code:
 * 502 when Sentinel failed the command, 409 when the capability was not negotiated.
 */
class SendFluxCommand
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(string $verb, array $payload, int $timeout = 30): array
    {
        $url = config('constants.flux.internal_url');
        $token = config('constants.flux.internal_token');
        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            throw new RuntimeException('Flux internal API configuration is incomplete.');
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout($timeout)
            ->post(rtrim($url, '/').'/v1/commands/'.$verb, $payload);
        if ($response->failed()) {
            $reason = trim(mb_substr($response->body(), 0, 1000));
            throw new RuntimeException(match ($response->status()) {
                409 => "The server does not support {$verb}. Upgrade Sentinel and try again.",
                default => $reason === '' ? "Flux rejected {$verb} with HTTP {$response->status()}." : $reason,
            }, $response->status());
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException("Flux returned an invalid {$verb} result.");
        }

        return $data;
    }
}
