<?php

namespace App\Actions\Sentinel;

use App\Models\Server;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class PingFluxConnection
{
    use AsAction;

    /**
     * @return array{command_id: string, nonce: string, sentinel_time_unix_ms: int, sentinel_version: string, boot_id: string, latency_ms: int}
     */
    public function handle(Server $server): array
    {
        $url = config('constants.flux.internal_url');
        $token = config('constants.flux.internal_token');
        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            throw new RuntimeException('Flux internal API configuration is incomplete.');
        }

        $startedAt = hrtime(true);
        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(12)
            ->post(rtrim($url, '/').'/v1/commands/system.ping', [
                'server_id' => $server->uuid,
            ]);
        $response->throw();
        $validator = Validator::make($response->json(), [
            'command_id' => ['required', 'string'],
            'nonce' => ['required', 'string'],
            'sentinel_time_unix_ms' => ['required', 'integer'],
            'sentinel_version' => ['required', 'string'],
            'boot_id' => ['required', 'string'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('Flux returned an invalid ping response.');
        }

        return [
            ...$validator->validated(),
            'latency_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
        ];
    }
}
