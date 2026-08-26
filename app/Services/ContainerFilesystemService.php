<?php

namespace App\Services;

use App\Data\FileEntry;
use App\Models\LocalFileVolume;
use App\Models\Server;
use Illuminate\Support\Str;

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
        // type<TAB>size<TAB>mtime<TAB>perms<TAB>name. Portable across
        // busybox/coreutils (%a = octal permission bits).
        // Names containing a tab/newline are skipped by parseListing (v1 scope).
        $inner = 'cd '.$escapedPath.' 2>/dev/null || exit 0; '
            .'for e in * .*; do '
            .'case "$e" in .|..) continue;; esac; '
            .'[ -e "$e" ] || [ -L "$e" ] || continue; '
            .'if [ -L "$e" ]; then t=symlink; elif [ -d "$e" ]; then t=dir; else t=file; fi; '
            .'s=$(stat -c %s "$e" 2>/dev/null || echo 0); '
            .'m=$(stat -c %Y "$e" 2>/dev/null || echo 0); '
            .'p=$(stat -c %a "$e" 2>/dev/null || echo ""); '
            .'printf "%s\t%s\t%s\t%s\t%s\n" "$t" "$s" "$m" "$p" "$e"; '
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

    public function isEditable(string $path): bool
    {
        $escaped = $this->escapePath($path, 'read path');

        $size = (int) trim((string) instant_remote_process(
            [$this->dockerExecShell("stat -c %s {$escaped} 2>/dev/null || echo 0")],
            $this->server,
            throwError: false,
        ));
        if ($size > LocalFileVolume::MAX_CONTENT_SIZE) {
            return false;
        }

        // grep -qI exits non-zero for binary; echo text on success, binary otherwise.
        $kind = trim((string) instant_remote_process(
            [$this->dockerExecShell("grep -qI . {$escaped} && echo text || echo binary")],
            $this->server,
            throwError: false,
        ));

        return $kind === 'text';
    }

    public function read(string $path): string
    {
        if (! $this->isEditable($path)) {
            throw new \RuntimeException('File is not editable (binary or too large).');
        }

        $escaped = $this->escapePath($path, 'read path');

        // base64 the content so instant_remote_process's trim() cannot corrupt
        // exact bytes (e.g. trailing newlines).
        $encoded = (string) instant_remote_process(
            [$this->dockerExecShell("base64 {$escaped}")],
            $this->server,
            throwError: false,
        );

        return (string) base64_decode(trim($encoded), true);
    }

    public function buildWriteCommand(string $path, string $content): string
    {
        $escaped = $this->escapePath($path, 'write path');
        $b64 = base64_encode($content);

        return $this->dockerExecShell("echo {$b64} | base64 -d > {$escaped}");
    }

    public function buildMkdirCommand(string $path): string
    {
        $escaped = $this->escapePath($path, 'mkdir path');

        return $this->dockerExecShell("mkdir -p -- {$escaped}");
    }

    public function buildCreateFileCommand(string $path): string
    {
        $escaped = $this->escapePath($path, 'create file path');

        // Create only if absent so an existing file is never truncated.
        return $this->dockerExecShell("[ -e {$escaped} ] || : > {$escaped}");
    }

    public function buildChmodCommand(string $path, string $mode): string
    {
        $mode = $this->normalizeMode($mode);
        $escaped = $this->escapePath($path, 'chmod path');

        return $this->dockerExecShell("chmod {$mode} -- {$escaped}");
    }

    public function buildRenameCommand(string $from, string $to): string
    {
        $escapedFrom = $this->escapePath($from, 'rename source');
        $escapedTo = $this->escapePath($to, 'rename target');

        return $this->dockerExecShell("mv -- {$escapedFrom} {$escapedTo}");
    }

    public function buildDeleteCommand(string $path): string
    {
        $escaped = $this->escapePath($path, 'delete path');

        return $this->dockerExecShell("rm -rf -- {$escaped}");
    }

    public function write(string $path, string $content): void
    {
        instant_remote_process([$this->buildWriteCommand($path, $content)], $this->server);
    }

    public function makeDirectory(string $path): void
    {
        instant_remote_process([$this->buildMkdirCommand($path)], $this->server);
    }

    public function createFile(string $path): void
    {
        instant_remote_process([$this->buildCreateFileCommand($path)], $this->server);
    }

    public function chmod(string $path, string $mode): void
    {
        instant_remote_process([$this->buildChmodCommand($path, $mode)], $this->server);
    }

    protected function normalizeMode(string $mode): string
    {
        $mode = trim($mode);
        if (! preg_match('/^[0-7]{3,4}$/', $mode)) {
            throw new \InvalidArgumentException('Invalid permission mode. Use octal like 644 or 0755.');
        }

        return $mode;
    }

    public function rename(string $from, string $to): void
    {
        instant_remote_process([$this->buildRenameCommand($from, $to)], $this->server);
    }

    public function delete(string $path): void
    {
        instant_remote_process([$this->buildDeleteCommand($path)], $this->server);
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
            $parts = explode("\t", $line, 5);
            // Accept both the 5-field (with perms) and legacy 4-field formats.
            if (count($parts) === 5) {
                [$type, $size, $mtime, $perms, $name] = $parts;
            } elseif (count($parts) === 4) {
                [$type, $size, $mtime, $name] = $parts;
                $perms = '';
            } else {
                continue;
            }
            $entries[] = new FileEntry($name, $type, (int) $size, (int) $mtime, trim($perms));
        }

        return FileEntry::sort($entries);
    }

    public function isDirectory(string $path): bool
    {
        $escaped = $this->escapePath($path, 'stat path');
        $result = trim((string) instant_remote_process(
            [$this->dockerExecShell("[ -d {$escaped} ] && echo dir || echo file")],
            $this->server,
            throwError: false,
        ));

        return $result === 'dir';
    }

    public function upload(string $localTmpPath, string $destPath): void
    {
        validateShellSafePath($destPath, 'upload path');
        $serverTmp = '/tmp/coolify-upload-'.Str::random(16);
        $escapedServerTmp = escapeshellarg($serverTmp);
        $escapedContainer = escapeshellarg($this->container);
        $escapedDest = escapeshellarg($destPath);

        try {
            instant_scp($localTmpPath, $serverTmp, $this->server);
            instant_remote_process(
                ["docker cp {$escapedServerTmp} {$escapedContainer}:{$escapedDest}"],
                $this->server,
            );
        } finally {
            instant_remote_process(["rm -f {$escapedServerTmp}"], $this->server, throwError: false);
        }
    }

    public function download(string $path): string
    {
        $escaped = $this->escapePath($path, 'download path');
        $escapedContainer = escapeshellarg($this->container);
        $isDir = $this->isDirectory($path);
        $serverTmp = '/tmp/coolify-download-'.Str::random(16).($isDir ? '.tar.gz' : '');
        $escapedServerTmp = escapeshellarg($serverTmp);
        $localTmp = storage_path('app/tmp/'.Str::random(16).($isDir ? '.tar.gz' : ''));
        @mkdir(dirname($localTmp), 0755, true);

        try {
            if ($isDir) {
                instant_remote_process(
                    ["docker exec {$escapedContainer} sh -c ".escapeshellarg("cd {$escaped} && tar czf - .")." > {$escapedServerTmp}"],
                    $this->server,
                );
            } else {
                instant_remote_process(
                    ["docker cp {$escapedContainer}:{$escaped} {$escapedServerTmp}"],
                    $this->server,
                );
            }
            instant_scp_from_server($serverTmp, $localTmp, $this->server);
        } finally {
            instant_remote_process(["rm -f {$escapedServerTmp}"], $this->server, throwError: false);
        }

        return $localTmp;
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
