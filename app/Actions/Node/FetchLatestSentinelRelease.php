<?php

namespace App\Actions\Node;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Reads the latest host Sentinel release from the `coolify.sentinel-host` entry in versions.json.
 * Returns null when host Sentinel is disabled or the entry is missing or invalid, so no upgrade is offered.
 */
class FetchLatestSentinelRelease
{
    use AsAction;

    public const CACHE_KEY = 'sentinel-host:latest-release';

    private const VERSION_PATTERN = '/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)\z/';

    private const DIGEST_PATTERN = '/\Asha256:[a-f0-9]{64}\z/';

    /** @return array{version: string, digest: string, image: string}|null */
    public function handle(): ?array
    {
        if (! config('constants.sentinel.host_enabled', false)) {
            return null;
        }

        $cached = Cache::remember(self::CACHE_KEY, now()->addMinutes(10), function (): array {
            try {
                $response = Http::connectTimeout(3)->timeout(5)->get(config('constants.coolify.versions_url'));

                return ['release' => $response->successful() ? self::parse($response->json()) : null];
            } catch (Throwable) {
                return ['release' => null];
            }
        });
        $release = data_get($cached, 'release');
        if (! is_array($release)) {
            return null;
        }

        return [...$release, 'image' => self::image($release['digest'])];
    }

    /** @return array{version: string, digest: string}|null */
    public static function parse(mixed $versions): ?array
    {
        $release = data_get($versions, 'coolify.sentinel-host');
        $version = data_get($release, 'version');
        $digest = data_get($release, 'digest');
        if (! self::isStableVersion($version) || ! is_string($digest) || ! preg_match(self::DIGEST_PATTERN, $digest)) {
            return null;
        }

        return ['version' => $version, 'digest' => $digest];
    }

    public static function isStableVersion(mixed $version): bool
    {
        return is_string($version) && preg_match(self::VERSION_PATTERN, $version) === 1;
    }

    public static function image(string $digest): string
    {
        return rtrim((string) config('constants.sentinel.host_repository'), '/').'@'.$digest;
    }

    /**
     * An upgrade is available when the release is valid and the running version is older or not a release
     * version (for example `main` or `1.0.2-dev+abc`). An unknown version means Sentinel never reported one.
     *
     * @param  array{version: string}|null  $release
     */
    public static function isUpgradeAvailable(?string $runningVersion, ?array $release): bool
    {
        if ($release === null || ! self::isStableVersion($release['version'] ?? null) || blank($runningVersion)) {
            return false;
        }

        return ! self::isStableVersion($runningVersion) || version_compare($runningVersion, $release['version'], '<');
    }
}
