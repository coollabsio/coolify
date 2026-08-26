<?php

namespace App\Services;

use App\Data\FileEntry;
use App\Models\Server;

class ContainerFilesystemService
{
    public function __construct(
        private Server $server,
        private string $container,
    ) {}

    public function buildListCommand(string $path): string
    {
        $escapedPath = $this->escapePath($path, 'list path');

        // Iterate `* .*`, skip . and .., guard literal globs, emit
        // type<TAB>size<TAB>mtime<TAB>name. Portable across busybox/coreutils.
        // Names containing a tab/newline are skipped by parseListing (v1 scope).
        $inner = 'cd '.$escapedPath.' 2>/dev/null || exit 0; '
            .'for e in * .*; do '
            .'case "$e" in .|..) continue;; esac; '
            .'[ -e "$e" ] || [ -L "$e" ] || continue; '
            .'if [ -L "$e" ]; then t=symlink; elif [ -d "$e" ]; then t=dir; else t=file; fi; '
            .'s=$(stat -c %s "$e" 2>/dev/null || echo 0); '
            .'m=$(stat -c %Y "$e" 2>/dev/null || echo 0); '
            .'printf "%s\t%s\t%s\t%s\n" "$t" "$s" "$m" "$e"; '
            .'done';

        return $this->dockerExecShell($inner);
    }

    /**
     * @return array<int, FileEntry>
     */
    public function list(string $path): array
    {
        $raw = instant_remote_process([$this->buildListCommand($path)], $this->server, throwError: false);

        return $this->parseListing($raw);
    }

    public function defaultRoot(): string
    {
        $escapedContainer = escapeshellarg($this->container);
        $workingDir = instant_remote_process(
            ["docker inspect --format '{{.Config.WorkingDir}}' {$escapedContainer}"],
            $this->server,
            throwError: false,
        );

        $workingDir = trim((string) $workingDir);

        return $workingDir !== '' ? $workingDir : '/';
    }

    /**
     * @return array<int, FileEntry>
     */
    public function parseListing(?string $raw): array
    {
        if (blank($raw)) {
            return [];
        }

        $entries = [];
        foreach (explode("\n", trim($raw)) as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode("\t", $line, 4);
            if (count($parts) !== 4) {
                continue;
            }
            [$type, $size, $mtime, $name] = $parts;
            $entries[] = new FileEntry($name, $type, (int) $size, (int) $mtime);
        }

        return FileEntry::sort($entries);
    }

    protected function escapePath(string $path, string $label): string
    {
        validateShellSafePath($path, $label);

        return escapeshellarg($path);
    }

    protected function dockerExecShell(string $innerShell): string
    {
        $escapedContainer = escapeshellarg($this->container);

        return "docker exec {$escapedContainer} sh -c ".escapeshellarg($innerShell);
    }
}
