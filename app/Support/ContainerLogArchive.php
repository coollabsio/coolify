<?php

namespace App\Support;

/**
 * Container logs that Sentinel archives on the server, so they stay readable after Docker removes the container.
 *
 * Sentinel writes `<owner-uuid>/<container-name>.<container-id-12>.log` under the archive root. Each line has the
 * `docker logs --timestamps` format, and a rotated file gets the suffix `.1`.
 */
class ContainerLogArchive
{
    private const FILE_PATTERN = '/^([A-Za-z0-9][A-Za-z0-9_.-]*)\.([0-9a-f]{12})\.log$/';

    private const OWNER_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]*$/';

    public static function directory(string $ownerUuid): string
    {
        return base_configuration_dir().'/sentinel/container-logs/'.$ownerUuid;
    }

    public static function isValidFile(string $file): bool
    {
        return preg_match(self::FILE_PATTERN, $file) === 1;
    }

    /**
     * Prints one `<mtime> <file>` line for each archived container of the owner. No output when nothing is archived.
     */
    public static function listCommand(string $ownerUuid): ?string
    {
        if (preg_match(self::OWNER_PATTERN, $ownerUuid) !== 1) {
            return null;
        }

        return 'find '.escapeshellarg(self::directory($ownerUuid))." -maxdepth 1 -type f -name '*.log' -printf '%T@ %f\\n' 2>/dev/null || true";
    }

    /**
     * Archives of containers that no longer exist, newest first.
     *
     * @param  list<string>  $existingContainerIds  IDs of the containers Docker still has, so their live logs are used instead.
     * @return list<array{server: string, file: string, container: string, id: string, modifiedAt: int}>
     */
    public static function parseList(string $output, string $serverUuid, array $existingContainerIds): array
    {
        $existing = array_flip(array_map(fn (string $id): string => substr($id, 0, 12), $existingContainerIds));
        $entries = [];
        foreach (explode("\n", $output) as $line) {
            $parts = explode(' ', trim($line), 2);
            if (count($parts) !== 2 || ! is_numeric($parts[0])) {
                continue;
            }
            if (preg_match(self::FILE_PATTERN, $parts[1], $matches) !== 1 || isset($existing[$matches[2]])) {
                continue;
            }
            $entries[] = [
                'server' => $serverUuid,
                'file' => $parts[1],
                'container' => $matches[1],
                'id' => $matches[2],
                'modifiedAt' => (int) $parts[0],
            ];
        }
        usort($entries, fn (array $left, array $right): int => $right['modifiedAt'] <=> $left['modifiedAt']);

        return $entries;
    }

    /**
     * Reads the archive like `docker logs`: the rotated file first, then the current one.
     *
     * @param  int|null  $tail  Number of newest lines, or null for all lines.
     */
    public static function readCommand(string $ownerUuid, string $file, ?int $tail, bool $timestamps): ?string
    {
        if (preg_match(self::OWNER_PATTERN, $ownerUuid) !== 1 || ! self::isValidFile($file)) {
            return null;
        }

        $path = self::directory($ownerUuid).'/'.$file;
        $command = 'cat '.escapeshellarg($path.'.1').' '.escapeshellarg($path).' 2>/dev/null';
        if ($tail !== null) {
            $command .= ' | tail -n '.max(1, $tail);
        }
        if (! $timestamps) {
            $command .= " | cut -d ' ' -f 2-";
        }

        return $command;
    }
}
