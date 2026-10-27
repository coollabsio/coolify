<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class CloudflareHttp
{
    /**
     * The connect timeout is longer than the 5 second DNS resolver retry, so one lost DNS query does not fail the request.
     * Only idempotent requests are retried after a connection failure; a POST can reach Cloudflare before the timeout.
     */
    public static function client(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(20)
            ->retry(2, 500, fn ($exception, $request, ?string $method) => $exception instanceof ConnectionException
                && in_array($method, ['GET', 'PUT', 'DELETE'], true), throw: false);
    }
}
