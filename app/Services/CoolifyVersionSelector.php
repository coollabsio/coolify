<?php

namespace App\Services;

use InvalidArgumentException;

class CoolifyVersionSelector
{
    public static function forManual(array $versions, string $currentVersion, string $channel): string
    {
        if (! in_array($channel, ['stable', 'rc'], true)) {
            throw new InvalidArgumentException("Unsupported update channel: {$channel}");
        }

        $stableVersion = data_get($versions, 'coolify.v4.version');
        $targetVersion = is_string($stableVersion) && $stableVersion !== '' ? $stableVersion : $currentVersion;

        if ($channel === 'rc') {
            $rcVersion = data_get($versions, 'coolify.rc.version');
            if (is_string($rcVersion) && $rcVersion !== '' && version_compare($rcVersion, $targetVersion, '>')) {
                $targetVersion = $rcVersion;
            }
        }

        return self::preventDowngrade($targetVersion, $currentVersion);
    }

    public static function forAutomatic(array $versions, string $currentVersion, string $scope): string
    {
        if (! in_array($scope, ['minor', 'patch'], true)) {
            throw new InvalidArgumentException("Unsupported automatic update scope: {$scope}");
        }

        if ($scope === 'patch') {
            $minorLine = self::minorLine($currentVersion);
            $minorVersions = data_get($versions, 'coolify.v4.minors', []);
            $targetVersion = is_array($minorVersions) ? ($minorVersions[$minorLine] ?? null) : null;
        } else {
            $targetVersion = data_get($versions, 'coolify.v4.version');
        }

        if (! is_string($targetVersion) || $targetVersion === '') {
            return $currentVersion;
        }

        if (! self::isStableRelease($targetVersion)) {
            return $currentVersion;
        }

        return self::preventDowngrade($targetVersion, $currentVersion);
    }

    public static function reconcileMetadata(array $versions, ?array $cachedVersions, string $currentVersion): array
    {
        foreach (['coolify.v4.version', 'coolify.rc.version'] as $path) {
            $fetchedVersion = data_get($versions, $path);
            $cachedVersion = data_get($cachedVersions, $path);

            if (is_string($cachedVersion)
                && (! is_string($fetchedVersion) || version_compare($cachedVersion, $fetchedVersion, '>'))) {
                data_set($versions, $path, $cachedVersion);
            }
        }

        $fetchedMinors = data_get($versions, 'coolify.v4.minors', []);
        $cachedMinors = data_get($cachedVersions, 'coolify.v4.minors', []);
        $fetchedMinors = is_array($fetchedMinors) ? $fetchedMinors : [];

        if (is_array($cachedMinors)) {
            foreach ($cachedMinors as $minorLine => $cachedVersion) {
                $fetchedVersion = $fetchedMinors[$minorLine] ?? null;
                if (is_string($cachedVersion)
                    && (! is_string($fetchedVersion) || version_compare($cachedVersion, $fetchedVersion, '>'))) {
                    $fetchedMinors[$minorLine] = $cachedVersion;
                }
            }
        }
        data_set($versions, 'coolify.v4.minors', $fetchedMinors);

        $stableVersion = data_get($versions, 'coolify.v4.version');
        if (self::isStableRelease($currentVersion)
            && is_string($stableVersion)
            && version_compare($stableVersion, $currentVersion, '<')) {
            data_set($versions, 'coolify.v4.version', $currentVersion);
        }

        return $versions;
    }

    public static function isReleaseCandidate(string $version): bool
    {
        return preg_match('/-rc\.\d+(?:\.|$)/', $version) === 1;
    }

    private static function isStableRelease(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+$/', ltrim($version, 'v')) === 1;
    }

    private static function minorLine(string $version): string
    {
        if (preg_match('/^(\d+)\.(\d+)/', ltrim($version, 'v'), $matches) !== 1) {
            return '';
        }

        return "{$matches[1]}.{$matches[2]}";
    }

    private static function preventDowngrade(string $targetVersion, string $currentVersion): string
    {
        return version_compare($targetVersion, $currentVersion, '<') ? $currentVersion : $targetVersion;
    }
}
