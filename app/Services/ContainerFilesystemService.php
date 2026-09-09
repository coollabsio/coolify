<?php

namespace App\Services;

use App\Data\FileEntry;
use App\Models\LocalFileVolume;
use App\Models\Server;
use Illuminate\Support\Str;

class ContainerFilesystemService
{
    /**
     * Inline `echo <base64> | base64 -d` writes travel as a single execve
     * argument, capped at ~128 KiB by MAX_ARG_STRLEN. base64 inflates by 4/3,
     * so anything above ~96 KiB of content must go through the docker cp path.
     */
    private const MAX_INLINE_WRITE_SIZE = 96 * 1024;

    public function __construct(
        private Server $server,
        private string $container,
    ) {}

    public function buildListCommand(string $path): string
    {
        $escapedPath = $this->escapePath($path, 'list path');

        // Emit the whole directory in ONE stat call instead of a per-entry loop
        // (three stat forks each). %F is the human file type ("directory",
        // "symbolic link", "regular file"), mapped to a token by parseListing.
        // Fields: type<TAB>size<TAB>mtime<TAB>perms<TAB>owner<TAB>group<TAB>name.
        // A real tab is embedded in the format because stat's -c does NOT expand
        // a backslash \t escape (unlike printf) - it would emit the literal text.
        // `* .*` covers hidden files; parseListing drops the . and .. rows.
        // Names containing a tab/newline are skipped by parseListing (v1 scope).
        $tab = "\t";
        $format = '%F'.$tab.'%s'.$tab.'%Y'.$tab.'%a'.$tab.'%U'.$tab.'%G'.$tab.'%n';
        $inner = 'cd '.$escapedPath.' 2>/dev/null || exit 0; '
            .'stat -c "'.$format.'" -- * .* 2>/dev/null';

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

    /**
     * Read an editable text file in a single SSH round trip.
     *
     * The remote command folds the size cap, binary probe and base64 read into
     * one docker exec: it prints TOOBIG or BINARY for a rejected file, otherwise
     * an "OK\n" header followed by the base64 payload (empty for a 0-byte file,
     * which is valid editable text). base64 keeps exact bytes safe from the
     * trailing-whitespace trim in instant_remote_process.
     *
     * @throws \RuntimeException when the file is binary or larger than the cap
     */
    public function read(string $path): string
    {
        $escaped = $this->escapePath($path, 'read path');
        $max = LocalFileVolume::MAX_CONTENT_SIZE;

        $cmd = 'sz=$(stat -c %s '.$escaped.' 2>/dev/null || echo 0); '
            .'if [ "$sz" -gt '.$max.' ]; then echo TOOBIG; exit 0; fi; '
            .'if [ -s '.$escaped.' ] && ! grep -qI . '.$escaped.'; then echo BINARY; exit 0; fi; '
            .'echo OK; base64 '.$escaped.' 2>/dev/null';

        $raw = (string) instant_remote_process(
            [$this->dockerExecShell($cmd)],
            $this->server,
            throwError: false,
        );

        $newline = strpos($raw, "\n");
        $status = $newline === false ? trim($raw) : substr($raw, 0, $newline);
        if ($status === 'TOOBIG' || $status === 'BINARY') {
            throw new \RuntimeException('File is not editable (binary or too large).');
        }

        $payload = $newline === false ? '' : substr($raw, $newline + 1);

        return (string) base64_decode(trim($payload), true);
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
        if (strlen($content) > self::MAX_INLINE_WRITE_SIZE) {
            $this->writeViaUpload($path, $content);

            return;
        }
        instant_remote_process([$this->buildWriteCommand($path, $content)], $this->server);
    }

    /**
     * Write content too large for a single shell argument by staging it to a
     * local temp file and reusing the docker cp upload path.
     */
    protected function writeViaUpload(string $path, string $content): void
    {
        validateShellSafePath($path, 'write path');
        $localTmp = storage_path('app/tmp/'.Str::random(16));
        @mkdir(dirname($localTmp), 0755, true);
        file_put_contents($localTmp, $content);
        try {
            $this->upload($localTmp, $path);
        } finally {
            @unlink($localTmp);
        }
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
            $parts = explode("\t", $line, 7);
            $owner = '';
            $group = '';
            // Accept the current 7-field format (with owner/group) and the
            // legacy 5-field (perms) and 4-field formats.
            if (count($parts) === 7) {
                [$type, $size, $mtime, $perms, $owner, $group, $name] = $parts;
            } elseif (count($parts) === 5) {
                [$type, $size, $mtime, $perms, $name] = $parts;
            } elseif (count($parts) === 4) {
                [$type, $size, $mtime, $name] = $parts;
                $perms = '';
            } else {
                continue;
            }
            // stat lists the directory itself and its parent; skip them.
            if ($name === '.' || $name === '..') {
                continue;
            }
            $entries[] = new FileEntry(
                $name,
                $this->normalizeType($type),
                (int) $size,
                (int) $mtime,
                trim($perms),
                trim($owner),
                trim($group),
            );
        }

        return FileEntry::sort($entries);
    }

    /**
     * Map a raw type field to a file/dir/symlink token. Accepts both the
     * already-tokenized legacy value and stat's human %F string.
     */
    protected function normalizeType(string $raw): string
    {
        $t = strtolower(trim($raw));
        if ($t === 'dir' || str_contains($t, 'directory')) {
            return 'dir';
        }
        if ($t === 'symlink' || str_contains($t, 'symbolic link')) {
            return 'symlink';
        }

        return 'file';
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
