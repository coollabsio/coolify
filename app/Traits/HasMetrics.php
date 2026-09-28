<?php

namespace App\Traits;

use App\Models\Server;
use App\Support\ValidationPatterns;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;

trait HasMetrics
{
    /**
     * @param  string|null  $container  Sentinel container name to read, defaults to the resource UUID. Not used for server metrics.
     */
    public function getCpuMetrics(int $mins = 5, ?string $container = null): ?array
    {
        return $this->getMetrics('cpu', $mins, 'percent', $container);
    }

    /**
     * @param  string|null  $container  Sentinel container name to read, defaults to the resource UUID. Not used for server metrics.
     */
    public function getMemoryMetrics(int $mins = 5, ?string $container = null): ?array
    {
        if ($this->isServerMetrics()) {
            return $this->getMetrics('memory', $mins, 'usedPercent');
        }

        $metrics = $this->getMetrics('memory', $mins, 'used', $container);
        if ($metrics === null) {
            return null;
        }

        return convertContainerMemoryBytesToMegabytes($metrics);
    }

    private function getMetrics(string $type, int $mins, string $valueField, ?string $container = null): ?array
    {
        $server = $this->getMetricsServer();
        if (! $server->isMetricsEnabled()) {
            return null;
        }

        $from = now()->subMinutes($mins)->toIso8601ZuluString();
        $endpoint = $this->getMetricsEndpoint($type, $from, $container);

        $previousToken = null;
        try {
            $previousToken = $server->settings->sentinel_token;
        } catch (DecryptException) {
            // fall through to ensureValidSentinelToken which will regenerate
        }
        $token = $server->settings->ensureValidSentinelToken();
        if ($token !== $previousToken) {
            Log::warning('Regenerated sentinel token during metrics read; sentinel container restart required', ['server_id' => $server->id]);
        }

        $response = instant_remote_process(
            ["docker exec coolify-sentinel sh -c 'curl -H \"Authorization: Bearer {$token}\" {$endpoint}'"],
            $server,
            false
        );

        if (str($response)->contains('error')) {
            $error = json_decode($response, true);
            $error = data_get($error, 'error', 'Something is not okay, are you okay?');
            if ($error === 'Unauthorized') {
                $error = 'Unauthorized, please check your metrics token or restart Sentinel to set a new token.';
            }
            throw new \Exception($error);
        }

        $metrics = collect(json_decode($response, true))->map(function ($metric) use ($valueField) {
            return [(int) $metric['time'], (float) ($metric[$valueField] ?? 0.0)];
        })->toArray();

        if ($mins > 60 && count($metrics) > 1000) {
            $metrics = downsampleLTTB($metrics, 1000);
        }

        return $metrics;
    }

    private function isServerMetrics(): bool
    {
        return $this instanceof Server;
    }

    private function getMetricsServer(): Server
    {
        return $this->isServerMetrics() ? $this : $this->destination->server;
    }

    private function getMetricsEndpoint(string $type, string $from, ?string $container = null): string
    {
        $base = 'http://localhost:8888/api';
        if ($this->isServerMetrics()) {
            return "{$base}/{$type}/history?from={$from}";
        }

        $container ??= $this->uuid;
        if (! ValidationPatterns::isValidContainerName($container)) {
            throw new \InvalidArgumentException('Invalid container name.');
        }

        return "{$base}/container/{$container}/{$type}/history?from={$from}";
    }
}
