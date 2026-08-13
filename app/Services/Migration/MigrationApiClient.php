<?php

namespace App\Services\Migration;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MigrationApiClient
{
    public function __construct(
        private string $baseUrl,
        private string $token,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $response = $this->http()->get($this->url($path), $query);
        $this->throwIfFailed($response->status(), $response->json());

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = []): array
    {
        $response = $this->http()->post($this->url($path), $body);
        $this->throwIfFailed($response->status(), $response->json());

        return $response->json() ?? [];
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->token)
            ->acceptJson()
            ->timeout(60)
            ->connectTimeout(10)
            ->retry(3, 1000, throw: false);
    }

    private function url(string $path): string
    {
        $path = ltrim($path, '/');
        if (str_contains($this->baseUrl, '/api/v1')) {
            return $this->baseUrl.'/'.$path;
        }

        return $this->baseUrl.'/api/v1/'.$path;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function throwIfFailed(int $status, ?array $payload): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }

        $message = $payload['message'] ?? "Migration API request failed with HTTP {$status}.";

        throw new RuntimeException((string) $message);
    }
}
