<?php

namespace App\Services;

use App\Data\Traffic\TrafficBreakdownData;
use App\Data\Traffic\TrafficOverviewData;
use App\Data\Traffic\TrafficPathData;
use App\Models\Server;
use Illuminate\Support\Collection;

class SentinelTrafficClient
{
    private string $base = 'http://localhost:8888/api';

    public function __construct(protected Server $server) {}

    // NOTE: Sentinel's traffic API expects `from`/`to` as ISO-8601 Zulu strings
    // (e.g. "2024-01-14T10:00:00Z"), confirmed against sentinel/API.md.
    public function overview(?string $appKey, string $from, string $to): TrafficOverviewData
    {
        $path = $appKey ? "/app/{$appKey}/traffic/overview" : '/traffic/overview';
        $json = json_decode($this->raw($this->url($path, ['from' => $from, 'to' => $to])), true) ?? [];

        return TrafficOverviewData::fromSentinel($json);
    }

    public function paths(?string $appKey, string $from, string $to, int $limit = 50): Collection
    {
        $path = $appKey ? "/app/{$appKey}/traffic/paths" : '/traffic/paths';
        $rows = json_decode($this->raw($this->url($path, ['from' => $from, 'to' => $to, 'limit' => $limit])), true) ?? [];

        return collect($rows)->map(fn ($r) => TrafficPathData::fromSentinel($r));
    }

    public function breakdown(?string $appKey, string $dimension, string $from, string $to, int $limit = 50): Collection
    {
        $path = $appKey ? "/app/{$appKey}/traffic/breakdown/{$dimension}" : "/traffic/breakdown/{$dimension}";
        $rows = json_decode($this->raw($this->url($path, ['from' => $from, 'to' => $to, 'limit' => $limit])), true) ?? [];

        return collect($rows)->map(fn ($r) => TrafficBreakdownData::fromSentinel($r));
    }

    public function apps(): array
    {
        return json_decode($this->raw($this->url('/traffic/apps')), true) ?? [];
    }

    public function attribution(): ?string
    {
        $json = json_decode($this->raw($this->url('/traffic/attribution')), true) ?? [];

        return data_get($json, 'attribution');
    }

    private function url(string $path, array $query = []): string
    {
        // Colons in ISO-8601 Zulu timestamps are safe in a query string; keep them
        // unencoded to match Sentinel's expected `from`/`to` format.
        $qs = empty($query) ? '' : '?'.str_replace('%3A', ':', http_build_query($query));

        return $this->base.$path.$qs;
    }

    protected function raw(string $url): string
    {
        $token = $this->server->settings->ensureValidSentinelToken();
        $response = instant_remote_process(
            ["docker exec coolify-sentinel sh -c 'curl -H \"Authorization: Bearer {$token}\" {$url}'"],
            $this->server,
            false
        );

        if (str($response)->contains('"error"')) {
            $error = data_get(json_decode($response, true), 'error', 'Traffic analytics request failed.');
            throw new \Exception($error);
        }

        return $response;
    }
}
