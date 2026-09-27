<?php

namespace App\Services\Dns;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Serializes managed DNS changes per team and hostname. The create path (check existing row, create the provider
 * record, add references) and the release path (re-check usage, delete the provider record and the row) both
 * run inside this lock, so a release can never delete a record that a concurrent create just started to use.
 */
class ManagedDnsHostnameLock
{
    public const TTL_SECONDS = 60;

    public const WAIT_SECONDS = 5;

    public function __construct(
        public int $waitSeconds = self::WAIT_SECONDS,
        public int $ttlSeconds = self::TTL_SECONDS,
    ) {}

    public static function key(int $teamId, string $hostname): string
    {
        return 'managed-dns:'.$teamId.':'.strtolower(rtrim($hostname, '.'));
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     *
     * @throws LockTimeoutException when another change for the hostname does not finish in time
     */
    public function run(int $teamId, string $hostname, callable $callback): mixed
    {
        return Cache::lock(self::key($teamId, $hostname), $this->ttlSeconds)->block($this->waitSeconds, $callback);
    }
}
