<?php

namespace App\Traits;

trait HasMetrics
{
    public function getCpuMetrics(int $mins = 5): ?array
    {
        return $this->getMetrics('cpu', $mins, 'percent');
    }

    public function getMemoryMetrics(int $mins = 5): ?array
    {
        $field = $this->isServerMetrics() ? 'usedPercent' : 'used';

        return $this->getMetrics('memory', $mins, $field);
    }

    private function getMetrics(string $type, int $mins, string $valueField): ?array
    {
        $server = $this->getMetricsServer();
        if (! $server->isMetricsEnabled()) {
            return null;
        }

        $from = now()->subMinutes($mins)->toIso8601ZuluString();
        $endpoint = $this->getMetricsEndpoint($type, $from);

        $response = instant_remote_process(
            ["docker exec coolify-sentinel sh -c 'curl -H \"Authorization: Bearer {$server->settings->sentinel_token}\" {$endpoint}'"],
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
        return $this instanceof \App\Models\Server;
    }

    private function getMetricsServer(): \App\Models\Server
    {
        return $this->isServerMetrics() ? $this : $this->destination->server;
    }

    private function getMetricsEndpoint(string $type, string $from): string
    {
        $base = 'http://localhost:8888/api';
        if ($this->isServerMetrics()) {
            return "{$base}/{$type}/history?from={$from}";
        }

        return "{$base}/container/{$this->uuid}/{$type}/history?from={$from}";
    }
}
