<?php

namespace App\Services\Docker;

use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class DockerNetworkCatalogRefresher
{
    private const COOLDOWN_SECONDS = 15;

    public function __construct(
        private readonly DockerNetworkScanner $scanner = new DockerNetworkScanner,
    ) {}

    public function refresh(Server $server, bool $force = false): Collection
    {
        $cacheKey = $this->cacheKey($server);
        $cached = Cache::get($cacheKey);

        if (! $force && is_array($cached) && $this->isFresh($cached)) {
            return collect([
                'found' => data_get($cached, 'found', 0),
                'created' => data_get($cached, 'created', 0),
                'updated' => data_get($cached, 'updated', 0),
                'marked_inactive' => data_get($cached, 'marked_inactive', 0),
                'errors' => data_get($cached, 'errors', []),
                'networks' => collect(),
            ]);
        }

        $result = $this->scanner->sync($server);

        Cache::put($cacheKey, [
            'scanned_at' => now()->timestamp,
            'found' => $result->get('found', 0),
            'created' => $result->get('created', 0),
            'updated' => $result->get('updated', 0),
            'marked_inactive' => $result->get('marked_inactive', 0),
            'errors' => $result->get('errors', []),
        ], now()->addSeconds(self::COOLDOWN_SECONDS));

        return $result;
    }

    private function cacheKey(Server $server): string
    {
        return "docker-network-catalog-refresh:server:{$server->id}";
    }

    private function isFresh(array $cached): bool
    {
        $scannedAt = (int) data_get($cached, 'scanned_at', 0);

        return $scannedAt > 0 && $scannedAt >= now()->subSeconds(self::COOLDOWN_SECONDS)->timestamp;
    }
}
