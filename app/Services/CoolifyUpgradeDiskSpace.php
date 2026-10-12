<?php

namespace App\Services;

use App\Models\Server;

/**
 * An upgrade pulls new images and recreates the Coolify containers. When the disk becomes full
 * during an upgrade, Coolify can stop working (for example, an empty config cache file).
 */
class CoolifyUpgradeDiskSpace
{
    public const REQUIRED_GB = 5;

    public const UPGRADE_PATHS = ['/data/coolify'];

    public const DEFAULT_DOCKER_ROOT_DIR = '/var/lib/docker';

    /**
     * Free space in GB on the Docker data directory and /data/coolify. The smaller value is returned.
     * Returns null when the free space cannot be measured, so an unknown value never blocks an upgrade.
     */
    public function availableGb(Server $server): ?float
    {
        $dockerRootDir = trim((string) instant_remote_process(
            ["docker info --format '{{.DockerRootDir}}' 2>/dev/null || true"],
            $server,
            false
        ));

        $paths = collect([...self::UPGRADE_PATHS, $dockerRootDir ?: self::DEFAULT_DOCKER_ROOT_DIR])
            ->map(fn (string $path) => escapeshellarg($path))
            ->implode(' ');

        $output = instant_remote_process(["df -Pk {$paths} 2>/dev/null || true"], $server, false);

        return self::parseDfOutput((string) $output);
    }

    /**
     * Read the smallest "Available" column (in 1K blocks) from `df -Pk` output and return it in GB.
     */
    public static function parseDfOutput(string $output): ?float
    {
        $availableKb = collect(preg_split('/\R/', trim($output)))
            ->skip(1)
            ->map(fn (string $line) => preg_split('/\s+/', trim($line))[3] ?? null)
            ->filter(fn (?string $value) => $value !== null && ctype_digit($value))
            ->map(fn (string $value) => (int) $value)
            ->min();

        if ($availableKb === null) {
            return null;
        }

        return round($availableKb / 1024 / 1024, 1);
    }

    public static function isLow(?float $availableGb): bool
    {
        return $availableGb !== null && $availableGb < self::REQUIRED_GB;
    }
}
