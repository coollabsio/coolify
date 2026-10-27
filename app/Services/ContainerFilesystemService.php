<?php

namespace App\Services;

use App\Data\FileEntry;
use App\Exceptions\ContainerShellUnavailableException;
use App\Helpers\SshMultiplexingHelper;
use App\Models\LocalFileVolume;
use App\Models\Server;
use Illuminate\Support\Str;

class ContainerFilesystemService
{
    /**
     * Inline `echo <base64> | base64 -d` writes travel as a single execve
     * argument, capped at ~128 KiB by MAX_ARG_STRLEN. base64 inflates by 4/3,
     * so keep inline content below that limit with room for command quoting.
     */
    private const MAX_INLINE_WRITE_SIZE = 64 * 1024;

    /** Largest file, or uncompressed folder, the file browser may download. */
    public const MAX_DOWNLOAD_SIZE = 1024 * 1024 * 1024;

    /**
     * Linux capability names by bit number, as Docker expects them in --cap-add.
     */
    private const CAPABILITIES = [
        'CHOWN', 'DAC_OVERRIDE', 'DAC_READ_SEARCH', 'FOWNER', 'FSETID', 'KILL', 'SETGID', 'SETUID',
        'SETPCAP', 'LINUX_IMMUTABLE', 'NET_BIND_SERVICE', 'NET_BROADCAST', 'NET_ADMIN', 'NET_RAW',
        'IPC_LOCK', 'IPC_OWNER', 'SYS_MODULE', 'SYS_RAWIO', 'SYS_CHROOT', 'SYS_PTRACE', 'SYS_PACCT',
        'SYS_ADMIN', 'SYS_BOOT', 'SYS_NICE', 'SYS_RESOURCE', 'SYS_TIME', 'SYS_TTY_CONFIG', 'MKNOD',
        'LEASE', 'AUDIT_WRITE', 'AUDIT_CONTROL', 'SETFCAP', 'MAC_OVERRIDE', 'MAC_ADMIN', 'SYSLOG',
        'WAKE_ALARM', 'BLOCK_SUSPEND', 'AUDIT_READ', 'PERFMON', 'BPF', 'CHECKPOINT_RESTORE',
    ];

    /** @var array{user: string, groups: array<int, string>, capabilities: array<int, string>}|null */
    private ?array $helperIdentity = null;

    /**
     * With $useHelper, commands run in a short-lived helper container that
     * shares the PID namespace of the target and reaches its files through
     * /proc/1/root. This supports images without a shell (scratch, distroless).
     */
    public function __construct(
        private Server $server,
        private string $container,
        private bool $useHelper = false,
    ) {}

    public function buildListCommand(string $path): string
    {
        $escapedPath = $this->escapePath($path, 'list path');

        // Emit the whole directory through batched stat calls (one for most folders)
        // instead of a per-entry loop. %F is the human file type ("directory",
        // "symbolic link", "regular file"), mapped to a token by parseListing.
        // Fields: type<TAB>size<TAB>mtime<TAB>perms<TAB>owner<TAB>group<TAB>name.
        // A real tab is embedded in the format because stat's -c does NOT expand
        // a backslash \t escape (unlike printf) - it would emit the literal text.
        // POSIX `find ... -prune -exec {} +` lists hidden files without recursing
        // and batches names, so a huge directory cannot overflow the argument
        // limit like a `* .*` glob would. Names arrive as ./name; parseListing
        // strips that prefix. Names containing a tab/newline are skipped (v1 scope).
        $tab = "\t";
        $format = '%F'.$tab.'%s'.$tab.'%Y'.$tab.'%a'.$tab.'%U'.$tab.'%G'.$tab.'%n';
        // Exit 127 when find or stat is missing, so list() can switch to the helper.
        $inner = 'command -v find >/dev/null && command -v stat >/dev/null || exit 127; '
            .'cd '.$escapedPath.' 2>/dev/null || exit 0; '
            .'find . ! -name . -prune -exec stat -c "'.$format.'" -- {} + 2>/dev/null; exit 0';

        return $this->dockerExecShell($inner);
    }

    /**
     * docker exec exits with 126 or 127 when it cannot start `sh` or the
     * listing tools in the container. Other docker exec failures still list
     * as an empty folder. In helper mode, every failure is reported.
     *
     * @return array<int, FileEntry>
     *
     * @throws ContainerShellUnavailableException when the files cannot be listed this way
     */
    public function list(string $path): array
    {
        try {
            $raw = instant_remote_process([$this->buildListCommand($path)], $this->server);
        } catch (\RuntimeException $e) {
            if ($this->useHelper || in_array($e->getCode(), [126, 127], true)) {
                throw new ContainerShellUnavailableException($e->getMessage(), (int) $e->getCode(), $e);
            }

            return [];
        }

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

        $cmd = '[ -f '.$escaped.' ] && [ -r '.$escaped.' ] || exit 1; '
            .'sz=$(stat -Lc %s -- '.$escaped.') || exit 1; '
            .'if [ "$sz" -gt '.$max.' ]; then echo TOOBIG; exit 0; fi; '
            .'if [ -s '.$escaped.' ] && ! grep -qI \'\' -- '.$escaped.'; then echo BINARY; exit 0; fi; '
            .'echo OK; base64 -- '.$escaped.' || exit 1';

        $raw = (string) instant_remote_process(
            [$this->dockerExecShell($cmd)],
            $this->server,
        );

        $newline = strpos($raw, "\n");
        $status = $newline === false ? trim($raw) : substr($raw, 0, $newline);
        if ($status === 'TOOBIG' || $status === 'BINARY') {
            throw new \RuntimeException('File is not editable (binary or too large).');
        }

        $payload = $newline === false ? '' : substr($raw, $newline + 1);

        $content = base64_decode(trim($payload), true);
        if ($status !== 'OK' || $content === false || strlen($content) > $max) {
            throw new \RuntimeException('Could not read file.');
        }
        // BusyBox grep ignores -I, so a binary file can pass the remote probe.
        if (str_contains($content, "\0")) {
            throw new \RuntimeException('File is not editable (binary or too large).');
        }

        return $content;
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
            // find prefixes names with ./; a name itself can never contain a slash.
            if (str_starts_with($name, './')) {
                $name = substr($name, 2);
            }
            if ($name === '' || $name === '.' || $name === '..') {
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

    /**
     * Inspect a download target in one round trip. Folder size is the
     * uncompressed disk usage, so the archive is never larger than reported.
     *
     * @return array{isDir: bool, size: int}
     *
     * @throws \RuntimeException when the path does not exist
     */
    public function downloadInfo(string $path): array
    {
        $escaped = $this->escapePath($path, 'download path');
        $cmd = 'if [ -d '.$escaped.' ]; then echo "dir $(du -sk '.$escaped.' | cut -f1)"; '
            .'elif [ -e '.$escaped.' ]; then echo "file $(stat -L -c %s '.$escaped.')"; fi';

        $raw = trim((string) instant_remote_process([$this->dockerExecShell($cmd)], $this->server));
        [$kind, $size] = array_pad(explode(' ', $raw, 2), 2, '');
        if (! in_array($kind, ['dir', 'file'], true) || ! ctype_digit($size)) {
            throw new \RuntimeException('File not found.');
        }

        return [
            'isDir' => $kind === 'dir',
            'size' => $kind === 'dir' ? (int) $size * 1024 : (int) $size,
        ];
    }

    public function buildDownloadCommand(string $path, bool $isDir): string
    {
        $escaped = $this->escapePath($path, 'download path');
        if ($this->useHelper) {
            return $this->dockerExecShell($isDir ? "tar czf - -C {$escaped} ." : "cat -- {$escaped}");
        }
        $escapedContainer = escapeshellarg($this->container);

        return $isDir
            ? "docker exec {$escapedContainer} tar czf - -C {$escaped} ."
            : "docker exec {$escapedContainer} cat -- {$escaped}";
    }

    /**
     * Pipe a file, or a folder as .tar.gz, from the container to $write in
     * chunks. Nothing is stored on the Coolify host or the managed server.
     *
     * @param  callable(string): void  $write
     */
    public function streamDownload(string $path, bool $isDir, callable $write): void
    {
        $command = $this->buildDownloadCommand($path, $isDir);
        if ($this->server->isNonRoot()) {
            $command = parseCommandsByLineForSudo(collect([$command]), $this->server)[0];
        }

        $process = proc_open($this->sshCommand($command), [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new \RuntimeException('Could not start the download.');
        }

        try {
            while (! feof($pipes[1])) {
                $chunk = fread($pipes[1], 64 * 1024);
                if ($chunk === false || connection_aborted()) {
                    break;
                }
                if ($chunk !== '') {
                    $write($chunk);
                }
            }
        } finally {
            fclose($pipes[1]);
            proc_terminate($process);
            proc_close($process);
        }
    }

    /**
     * Stage the file, or the folder as .tar.gz, on the managed server and PUT
     * it to a presigned S3 URL. S3 needs a Content-Length, so a pipe without a
     * known size cannot be uploaded directly. The temp file is always removed.
     *
     * @param  array<string, string>  $headers
     */
    public function buildS3UploadCommand(string $path, bool $isDir, string $url, array $headers): string
    {
        $headerArgs = FileDownloadStorageService::curlHeaderArgs($headers);
        $script = 'tmp=$(mktemp /tmp/coolify-download-XXXXXXXXXX) || exit 1; '
            .'trap \'rm -f "$tmp"\' EXIT; '
            .$this->buildDownloadCommand($path, $isDir).' > "$tmp" && '
            .'curl -fsS --retry 2 '.$headerArgs.' -T "$tmp" '.escapeshellarg($url);

        return 'sh -c '.escapeshellarg($script);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function uploadToS3(string $path, bool $isDir, string $url, array $headers): void
    {
        instant_remote_process([$this->buildS3UploadCommand($path, $isDir, $url, $headers)], $this->server, timeout: 3600);
    }

    protected function sshCommand(string $command): string
    {
        return SshMultiplexingHelper::generateSshCommand($this->server, $command);
    }

    protected function escapePath(string $path, string $label): string
    {
        validateShellSafePath($path, $label);

        return escapeshellarg(($this->useHelper ? '/proc/1/root' : '').$path);
    }

    protected function dockerExecShell(string $innerShell): string
    {
        $escapedContainer = escapeshellarg($this->container);
        if (! $this->useHelper) {
            return "docker exec {$escapedContainer} sh -c ".escapeshellarg($innerShell);
        }

        // The helper gets exactly the user, groups and capabilities of the
        // target process: the kernel requires them to reach /proc/1/root,
        // and nothing more is given. It has no network, a read-only root
        // file system, and no Docker socket or host mounts, and it is removed
        // when the command ends. Exit 126 means the kernel denied access.
        $identity = $this->helperIdentity();
        $options = [
            '--rm',
            '--pid container:'.$escapedContainer,
            '--user '.escapeshellarg($identity['user']),
            ...array_map(fn (string $group) => '--group-add '.escapeshellarg($group), $identity['groups']),
            '--cap-drop ALL',
            ...array_map(fn (string $capability) => '--cap-add '.$capability, $identity['capabilities']),
            '--security-opt no-new-privileges',
            '--network none',
            '--ipc none',
            '--read-only',
            '--log-driver none',
            '--pids-limit 64',
            '--label coolify.file-browser=true',
            "--entrypoint ''",
        ];
        $image = escapeshellarg(coolifyHelperImage().':'.getHelperVersion());

        return 'docker run '.implode(' ', $options)." {$image} sh -c "
            .escapeshellarg('[ -x /proc/1/root/ ] || exit 126; '.$innerShell);
    }

    /**
     * Read the effective user, group, supplementary groups and permitted
     * capabilities of the main process of the target container.
     *
     * @return array{user: string, groups: array<int, string>, capabilities: array<int, string>}
     */
    protected function helperIdentity(): array
    {
        if ($this->helperIdentity !== null) {
            return $this->helperIdentity;
        }

        $escapedContainer = escapeshellarg($this->container);
        $status = (string) instant_remote_process(
            ["cat /proc/$(docker inspect --format '{{.State.Pid}}' {$escapedContainer})/status"],
            $this->server,
        );

        return $this->helperIdentity = $this->parseProcessStatus($status);
    }

    /**
     * @return array{user: string, groups: array<int, string>, capabilities: array<int, string>}
     */
    public function parseProcessStatus(string $status): array
    {
        $fields = [];
        foreach (explode("\n", $status) as $line) {
            [$key, $value] = array_pad(explode(':', $line, 2), 2, '');
            $fields[trim($key)] = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        }

        $uid = $fields['Uid'][1] ?? '';
        $gid = $fields['Gid'][1] ?? '';
        $capabilityMask = $fields['CapPrm'][0] ?? '';
        if (! ctype_digit($uid) || ! ctype_digit($gid) || ! ctype_xdigit($capabilityMask) || strlen($capabilityMask) > 16) {
            throw new \RuntimeException('Could not read the user of the container process.');
        }

        $groups = array_values(array_filter($fields['Groups'] ?? [], fn (string $group) => ctype_digit($group) && $group !== $gid));

        // Read the mask one hex digit at a time; hexdec() turns 64-bit masks into floats.
        $digits = strrev($capabilityMask);
        $capabilities = [];
        foreach (self::CAPABILITIES as $bit => $name) {
            if (hexdec($digits[intdiv($bit, 4)] ?? '0') & (1 << ($bit % 4))) {
                $capabilities[] = $name;
            }
        }

        return ['user' => "{$uid}:{$gid}", 'groups' => $groups, 'capabilities' => $capabilities];
    }
}
