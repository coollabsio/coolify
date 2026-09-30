<?php

namespace App\Models;

use App\Events\FileStorageChanged;
use App\Jobs\ServerStorageSaveJob;
use Closure;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Yaml;

class LocalFileVolume extends BaseModel
{
    public const MAX_CONTENT_SIZE = 5_242_880;

    public const BINARY_PLACEHOLDER = '[binary file]';

    public const TOO_LARGE_PLACEHOLDER = '[file too large to display]';

    /**
     * Resolves $1 and $2 like `realpath -m`, but only with POSIX sh and `readlink -f`, which
     * BusyBox also has. It walks up to the deepest existing path, resolves it, and appends the
     * missing rest. A dangling symlink or `.`/`..` in the missing rest fails closed.
     */
    private const REMOTE_PATH_CONFINEMENT_SCRIPT = <<<'SH'
        resolve() {
            path=$1
            rest=
            case $path in /*) ;; *) return 1 ;; esac
            while [ ! -e "$path" ]; do
                if [ -L "$path" ]; then return 1; fi
                rest=/${path##*/}$rest
                path=${path%/*}
                [ -n "$path" ] || path=/
            done
            case "$rest/" in */./*|*/../*) return 1 ;; esac
            path=$(readlink -f "$path") || return 1
            printf "%s\n" "${path%/}$rest"
        }
        base=$(resolve "$1") || exit 1
        target=$(resolve "$2") || exit 1
        case $target in "$base"|"$base"/*) echo OK ;; *) echo NOK ;; esac
        SH;

    /**
     * Prints `<number>:<state>` for each path argument, in order. It only reads. A state is `file`
     * (also a symlink to a file), `missing`, `empty-directory`, `directory` (not empty) or `other`
     * (another symlink or a special file). A directory that it cannot read is `directory`.
     */
    private const REMOTE_FILE_STATE_SCRIPT = <<<'SH'
        i=0
        for path in "$@"; do
            i=$((i + 1))
            if [ -L "$path" ]; then
                if [ -f "$path" ]; then state=file; else state=other; fi
            elif [ -f "$path" ]; then
                state=file
            elif [ -d "$path" ]; then
                state=directory
                if entries=$(ls -A "$path" 2>/dev/null); then
                    if [ -z "$entries" ]; then state=empty-directory; fi
                fi
            elif [ -e "$path" ]; then
                state=other
            else
                state=missing
            fi
            printf "%s:%s\n" "$i" "$state"
        done
        SH;

    protected $casts = [
        // 'fs_path' => 'encrypted',
        // 'mount_path' => 'encrypted',
        'content' => 'encrypted',
        'is_directory' => 'boolean',
        'is_host_file' => 'boolean',
        'is_preview_suffix_enabled' => 'boolean',
    ];

    protected $hidden = [
        'content',
    ];

    use HasFactory;

    protected $fillable = [
        'fs_path',
        'mount_path',
        'content',
        'resource_type',
        'resource_id',
        'is_directory',
        'is_host_file',
        'is_based_on_git',
        'is_preview_suffix_enabled',
    ];

    public $appends = ['is_binary', 'is_too_large'];

    protected static function booted()
    {
        static::created(function (LocalFileVolume $fileVolume) {
            if ($fileVolume->is_host_file) {
                return;
            }

            ServerStorageSaveJob::dispatch($fileVolume)->afterCommit();
        });

        static::deleting(function (LocalFileVolume $fileVolume): void {
            if ($fileVolume->scheduledBackups()->exists()) {
                throw new \RuntimeException('Delete this directory backup schedule and its archives before deleting the directory.');
            }
        });
    }

    protected function isBinary(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->content === self::BINARY_PLACEHOLDER
        );
    }

    protected function isTooLarge(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->content === self::TOO_LARGE_PLACEHOLDER
        );
    }

    public function resource(): MorphTo
    {
        return $this->morphTo();
    }

    public function service(): MorphTo
    {
        return $this->morphTo('resource');
    }

    public function scheduledBackups(): MorphMany
    {
        return $this->morphMany(ScheduledVolumeBackup::class, 'backupable');
    }

    public function abortIfScheduledBackupsExist(): void
    {
        if ($this->scheduledBackups()->exists()) {
            abort(422, 'Delete this directory backup schedule and its archives before deleting the directory.');
        }
    }

    public function loadStorageOnServer()
    {
        if ($this->is_host_file) {
            return;
        }

        $this->load(['service']);
        $isService = data_get($this->resource, 'service');
        if ($isService) {
            $workdir = $this->resource->service->workdir();
            $server = $this->resource->service->server;
        } else {
            $workdir = $this->resource->workdir();
            $server = $this->resource->destination->server;
        }
        $commands = collect([]);
        $path = data_get_str($this, 'fs_path');
        if ($path->startsWith('.')) {
            $path = $path->after('.');
            $path = $workdir.$path;
        }

        if (! $this->isAdminControlledComposeMount()) {
            $path = str(confinePathToBase($workdir, $path->value(), 'storage path'));
            $this->assertRemotePathIsConfined($workdir, $path->value(), $server);
        }

        // Validate and escape path to prevent command injection
        validateShellSafePath($path, 'storage path');
        $escapedPath = escapeshellarg($path);

        $isFile = instant_remote_process(["test -f {$escapedPath} && echo OK || echo NOK"], $server);
        if ($isFile === 'OK') {
            if ($this->remoteFileExceedsLimit($escapedPath, $server)) {
                $this->content = self::TOO_LARGE_PLACEHOLDER;
                $this->is_directory = false;
                $this->save();

                return;
            }
            $content = $this->readRemoteFileContent($escapedPath, $server);
            // Check if content contains binary data by looking for null bytes or non-printable characters
            if ($content !== self::TOO_LARGE_PLACEHOLDER && (str_contains($content, "\0") || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $content))) {
                $content = self::BINARY_PLACEHOLDER;
            }
            $this->content = $content;
            $this->is_directory = false;
            $this->save();
        }
    }

    protected function remoteFileExceedsLimit(string $escapedPath, $server): bool
    {
        $sizeOutput = instant_remote_process(
            ["stat -c%s {$escapedPath} 2>/dev/null"],
            $server,
            false,
        );
        $size = (int) trim((string) $sizeOutput);

        return $size > self::MAX_CONTENT_SIZE;
    }

    /**
     * Cap the remote read itself so a file that grows after the size check
     * cannot be fully slurped into PHP memory.
     */
    protected function readRemoteFileContent(string $escapedPath, $server): string
    {
        $readLimit = self::MAX_CONTENT_SIZE + 1;
        $content = instant_remote_process(["head -c {$readLimit} {$escapedPath}"], $server, false);

        return self::contentFromBoundedRead($content);
    }

    public static function contentFromBoundedRead(?string $content): string
    {
        if (strlen((string) $content) > self::MAX_CONTENT_SIZE) {
            return self::TOO_LARGE_PLACEHOLDER;
        }

        return (string) $content;
    }

    /**
     * Without a server, Coolify deletes the file on every server of the resource.
     */
    public function deleteStorageOnServer(?Server $server = null)
    {
        if ($this->is_host_file) {
            return;
        }
        if (is_null($server)) {
            return $this->runOnEveryServer(fn (Server $server) => $this->deleteStorageOnServer($server));
        }

        $this->load(['service']);
        $isService = data_get($this->resource, 'service');
        $workdir = $isService ? $this->resource->service->workdir() : $this->resource->workdir();
        $commands = collect([]);
        $path = data_get_str($this, 'fs_path');
        if ($path->startsWith('.')) {
            $path = $path->after('.');
            $path = $workdir.$path;
        }

        if (! $this->isAdminControlledComposeMount()) {
            $path = str(confinePathToBase($workdir, $path->value(), 'storage path'));
            $this->assertRemotePathIsConfined($workdir, $path->value(), $server);
        }

        // Validate and escape path to prevent command injection
        validateShellSafePath($path, 'storage path');
        $escapedPath = escapeshellarg($path);

        $isFile = instant_remote_process(["test -f {$escapedPath} && echo OK || echo NOK"], $server);
        $isDir = instant_remote_process(["test -d {$escapedPath} && echo OK || echo NOK"], $server);
        if ($path && $path != '/' && $path != '.' && $path != '..') {
            if ($isFile === 'OK') {
                $commands->push("rm -rf {$escapedPath} > /dev/null 2>&1 || true");
            } elseif ($isDir === 'OK') {
                $commands->push("rm -rf {$escapedPath} > /dev/null 2>&1 || true");
                $commands->push("rmdir {$escapedPath} > /dev/null 2>&1 || true");
            }
        }
        if ($commands->count() > 0) {
            return instant_remote_process($commands, $server);
        }
    }

    /**
     * Without a server, Coolify writes the file on every server of the resource.
     */
    public function saveStorageOnServer(?Server $server = null)
    {
        if ($this->is_host_file) {
            return;
        }
        if (is_null($server)) {
            return $this->runOnEveryServer(fn (Server $server) => $this->saveStorageOnServer($server));
        }

        $this->load(['service']);
        $isService = data_get($this->resource, 'service');
        $workdir = $isService ? $this->resource->service->workdir() : $this->resource->workdir();
        $commands = collect([]);
        $escapedWorkdir = escapeshellarg($workdir);

        if ($this->is_directory) {
            // Validate fs_path early before any shell interpolation
            validateShellSafePath($this->fs_path, 'storage path');
            $escapedFsPath = escapeshellarg($this->fs_path);
            $commands->push("mkdir -p {$escapedFsPath} > /dev/null 2>&1 || true");
            $commands->push("mkdir -p {$escapedWorkdir} > /dev/null 2>&1 || true");
        }
        $path = data_get_str($this, 'fs_path');
        $content = data_get($this, 'content');
        $writesContent = $this->writesContentOnServer();
        if ($path->startsWith('.')) {
            $path = $path->after('.');
            $path = $workdir.$path;
        }

        if ($writesContent) {
            $path = str($this->confinedContentPath($path->value(), $server));
        } elseif (! $this->isAdminControlledComposeMount()) {
            $path = str(confinePathToBase($workdir, $path->value(), 'storage path'));
            $this->assertRemotePathIsConfined($workdir, $path->value(), $server);
        }

        $pathForParentDirectory = $writesContent ? $path : str($this->fs_path);
        if ($pathForParentDirectory->startsWith('.') || $pathForParentDirectory->startsWith('/') || $pathForParentDirectory->startsWith('~')) {
            $parent_dir = $pathForParentDirectory->beforeLast('/');
            if ($parent_dir != '') {
                $escapedParentDir = escapeshellarg($parent_dir);
                $commands->push("mkdir -p {$escapedParentDir} > /dev/null 2>&1 || true");
            }
        }

        // Validate and escape resolved path (may differ from fs_path if relative)
        validateShellSafePath($path, 'storage path');
        $escapedPath = escapeshellarg($path);

        $isFile = instant_remote_process(["test -f {$escapedPath} && echo OK || echo NOK"], $server);
        $isDir = instant_remote_process(["test -d {$escapedPath} && echo OK || echo NOK"], $server);
        $replacesEmptyDirectory = false;
        if ($isFile === 'OK' && $this->is_directory) {
            if ($this->remoteFileExceedsLimit($escapedPath, $server)) {
                $this->content = self::TOO_LARGE_PLACEHOLDER;
            } else {
                $this->content = $this->readRemoteFileContent($escapedPath, $server);
            }
            $this->is_directory = false;
            $this->save();
            FileStorageChanged::dispatch(data_get($server, 'team_id'));
            throw new \Exception('The following file is a file on the server, but you are trying to mark it as a directory. Please delete the file on the server or mark it as directory.');
        } elseif ($isDir === 'OK' && ! $this->is_directory) {
            if ($path === '/' || $path === '.' || $path === '..' || $path === '' || str($path)->isEmpty() || is_null($path)) {
                $this->is_directory = true;
                $this->save();
                throw new \Exception('The following file is a directory on the server, but you are trying to mark it as a file. <br><br>Please delete the directory on the server or mark it as directory.');
            }
            // Docker creates a missing bind source as an empty directory. Replace only an empty
            // directory; never delete files that are on the server.
            if (self::remoteFileStates([(string) $path], $server)[0] !== 'empty-directory') {
                throw new \Exception("The following file is a directory on the server, but you are trying to mark it as a file: {$path}<br><br>Please delete the directory on the server or mark it as directory.");
            }
            $replacesEmptyDirectory = true;
        }
        if (($isDir === 'NOK' || $replacesEmptyDirectory) && ! $this->is_directory) {
            if ($replacesEmptyDirectory) {
                $commands->push("rmdir {$escapedPath}");
            }
            $chmod = data_get($this, 'chmod');
            $chown = data_get($this, 'chown');
            if ($content) {
                $content = base64_encode($content);
                $commands->push("echo '$content' | base64 -d | tee {$escapedPath} > /dev/null");
            } else {
                $commands->push("touch {$escapedPath}");
            }
            $commands->push("chmod +x {$escapedPath}");
            if ($chown) {
                $commands->push('chown -- '.escapeshellarg($chown)." {$escapedPath}");
            }
            if ($chmod) {
                $commands->push('chmod -- '.escapeshellarg($chmod)." {$escapedPath}");
            }
        } elseif ($isDir === 'NOK' && $this->is_directory) {
            $commands->push("mkdir -p {$escapedPath} > /dev/null 2>&1 || true");
        }

        $result = instant_remote_process($commands, $server);
        if ($replacesEmptyDirectory) {
            FileStorageChanged::dispatch(data_get($server, 'team_id'));
        }

        return $result;
    }

    /**
     * One `sh -c` line with the paths as arguments, so the non-root sudo parser only puts sudo in
     * front of it and never changes the script. See REMOTE_FILE_STATE_SCRIPT for the output.
     *
     * @param  list<string>  $paths
     */
    public static function remoteFileStateCommand(array $paths): string
    {
        return 'sh -c '.escapeshellarg(self::REMOTE_FILE_STATE_SCRIPT).' sh '.implode(' ', array_map('escapeshellarg', $paths));
    }

    /**
     * Gets the state of each path with one server command. A path without a state in the output is `unknown`.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function remoteFileStates(array $paths, Server $server): array
    {
        $states = [];
        $output = (string) instant_remote_process([self::remoteFileStateCommand($paths)], $server, false);
        foreach (preg_split('/\R/', trim($output)) as $line) {
            if (preg_match('/^(\d+):([a-z-]+)$/', trim($line), $matches)) {
                $states[(int) $matches[1] - 1] = $matches[2];
            }
        }

        return array_map(fn (int $index) => $states[$index] ?? 'unknown', array_keys($paths));
    }

    /**
     * The absolute path where Coolify writes the content of this file on the server. This check is
     * only on the Coolify side; saveStorageOnServer() checks the path on the server again.
     *
     * @throws \RuntimeException If the path is not inside the resource directory
     */
    public function contentPathOnServer(): string
    {
        $path = (string) $this->fs_path;
        if (str_starts_with($path, '.')) {
            $path = $this->ownerResource()->workdir().substr($path, 1);
        }

        return $this->localConfinedContentPath($path)[1];
    }

    /**
     * Coolify writes this file from its content when the file is missing on the server. Files from
     * the Git repository, host files and placeholders for binary or large files are not written.
     */
    public function hasContentToWrite(): bool
    {
        return ! $this->is_directory
            && ! $this->is_host_file
            && ! $this->is_based_on_git
            && ! $this->is_binary
            && ! $this->is_too_large
            && (string) $this->content !== '';
    }

    /**
     * Reject symlink escapes immediately before a managed path is used remotely.
     */
    public static function assertRemotePathIsConfined(string $baseDirectory, string $path, Server $server): void
    {
        $result = instant_remote_process([self::remotePathConfinementCommand($baseDirectory, $path)], $server, false);

        if (trim((string) $result) !== 'OK') {
            throw new \RuntimeException('Invalid storage path: resolved path must stay inside the resource configuration directory.');
        }
    }

    /**
     * One `sh -c` line with the paths as arguments, so the non-root sudo parser only puts
     * sudo in front of it and never changes the script.
     */
    public static function remotePathConfinementCommand(string $baseDirectory, string $path): string
    {
        return 'sh -c '.escapeshellarg(self::REMOTE_PATH_CONFINEMENT_SCRIPT).' sh '.escapeshellarg($baseDirectory).' '.escapeshellarg($path);
    }

    /**
     * Coolify writes file content (from Compose `content:`, the UI or the API) to the server.
     */
    public function writesContentOnServer(): bool
    {
        return ! $this->is_directory && (string) $this->content !== '';
    }

    /**
     * Coolify writes file content only inside the resource directory, also for Compose bind mounts
     * that an administrator can point anywhere. The path must be below the directory (not the
     * directory itself), and it must stay inside it after the server resolves symlinks.
     *
     * @throws \RuntimeException If the path is not inside the resource directory
     */
    public function confinedContentPath(string $path, Server $server): string
    {
        [$baseDirectory, $confinedPath] = $this->localConfinedContentPath($path);

        try {
            self::assertRemotePathIsConfined($baseDirectory, $confinedPath, $server);
        } catch (\RuntimeException) {
            throw $this->contentOutsideResourceDirectoryException($path);
        }

        return $confinedPath;
    }

    /**
     * The first resource base directory that contains the path (not the directory itself), and the
     * normalized path. This check is only on the Coolify side.
     *
     * @return array{0: string, 1: string}
     *
     * @throws \RuntimeException If the path is not inside the resource directory
     */
    protected function localConfinedContentPath(string $path): array
    {
        foreach ($this->contentBaseDirectories() as $baseDirectory) {
            try {
                $confinedPath = confinePathToBase($baseDirectory, $path, 'storage path');
            } catch (\Exception) {
                continue;
            }
            if ($confinedPath === normalizeUnixPath($baseDirectory)) {
                continue;
            }

            return [$baseDirectory, $confinedPath];
        }

        throw $this->contentOutsideResourceDirectoryException($path);
    }

    protected function contentOutsideResourceDirectoryException(string $path): \RuntimeException
    {
        return new \RuntimeException(
            "Coolify writes file content only inside the resource directory. The path {$path} is outside of it. Use a relative source such as ./config/app.conf."
        );
    }

    /**
     * The resource workdir, and the directory where the Compose parser resolves `./` sources. They
     * differ only for services that use parser version 3.
     *
     * @return list<string>
     */
    public function contentBaseDirectories(): array
    {
        return array_values(array_unique([$this->ownerResource()->workdir(), $this->composeSourceDirectory()]));
    }

    protected function composeSourceDirectory(): string
    {
        $owner = $this->ownerResource();

        return $owner instanceof Application || $owner instanceof Service
            ? composeResourceDirectory($owner)
            : $owner->workdir();
    }

    /**
     * The main server of the resource, and the additional servers of an application.
     *
     * @return Collection<int, Server>
     */
    public function servers(): Collection
    {
        $this->load(['service']);
        if (data_get($this->resource, 'service')) {
            return collect([$this->resource->service->server]);
        }

        $servers = collect([$this->resource->destination->server]);
        if ($this->resource instanceof Application) {
            $servers = $servers->merge($this->resource->additional_servers);
        }

        return $servers->filter()->unique('id')->values();
    }

    /**
     * Runs the callback on every server, also when one server fails, and then throws the first error.
     *
     * @param  Closure(Server): mixed  $callback
     */
    protected function runOnEveryServer(Closure $callback): mixed
    {
        $result = null;
        $firstError = null;
        foreach ($this->servers() as $server) {
            try {
                $result = $callback($server);
            } catch (\Throwable $e) {
                $firstError ??= $e;
            }
        }
        if ($firstError) {
            throw $firstError;
        }

        return $result;
    }

    /**
     * The Application, Service or standalone database that owns the storage directory.
     */
    protected function ownerResource(): mixed
    {
        $resource = $this->resource;

        return $resource instanceof ServiceApplication || $resource instanceof ServiceDatabase
            ? $resource->service
            : $resource;
    }

    /**
     * Raw Compose bind mounts keep administrator-selected host path semantics.
     */
    protected function isAdminControlledComposeMount(): bool
    {
        $compose = data_get($this->resource, 'docker_compose_raw')
            ?? data_get($this->resource, 'service.docker_compose_raw');

        if (! is_string($compose) || $compose === '') {
            return false;
        }

        try {
            $services = data_get(Yaml::parse($compose), 'services', []);
            foreach ($services as $service) {
                foreach (data_get($service, 'volumes', []) as $volume) {
                    if (is_string($volume)) {
                        $parsed = parseDockerVolumeString($volume);
                        $source = data_get($parsed, 'source');
                        $target = data_get($parsed, 'target');
                    } else {
                        $source = data_get($volume, 'source');
                        $target = data_get($volume, 'target');
                    }

                    if ((string) $target !== $this->mount_path || ! sourceIsLocal(str((string) $source))) {
                        continue;
                    }

                    $sourceDirectory = str($this->composeSourceDirectory());
                    $fsPath = normalizeUnixPath($this->fs_path);
                    if (normalizeUnixPath(legacyReplaceLocalSource(str((string) $source), $sourceDirectory)->value()) === $fsPath) {
                        return true;
                    }
                    try {
                        $resolvedSource = replaceLocalSource(str((string) $source), $sourceDirectory)->value();
                    } catch (\Throwable) {
                        continue;
                    }
                    if (normalizeUnixPath($resolvedSource) === $fsPath) {
                        return true;
                    }
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    // Accessor for convenient access
    protected function plainMountPath(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->mount_path,
            set: fn ($value) => $this->mount_path = $value
        );
    }

    // Scope for searching
    public function scopeWherePlainMountPath($query, $path)
    {
        return $query->get()->where('plain_mount_path', $path);
    }

    // Check if this volume belongs to a service resource
    public function isServiceResource(): bool
    {
        return in_array($this->resource_type, [
            'App\Models\ServiceApplication',
            'App\Models\ServiceDatabase',
        ]);
    }

    // Determine if this volume should be read-only in the UI
    // File/directory mounts can be edited even for services
    public function shouldBeReadOnlyInUI(): bool
    {
        // Check for explicit :ro flag in compose (existing logic)
        return $this->isReadOnlyVolume();
    }

    // Check if this volume is read-only by parsing the docker-compose content
    public function isReadOnlyVolume(): bool
    {
        try {
            // Only check for services
            $service = $this->service;
            if (! $service || ! method_exists($service, 'service')) {
                return false;
            }

            $actualService = $service->service;
            if (! $actualService || ! $actualService->docker_compose_raw) {
                return false;
            }

            // Parse the docker-compose content
            $compose = Yaml::parse($actualService->docker_compose_raw);
            if (! isset($compose['services'])) {
                return false;
            }

            // Find the service that this volume belongs to
            $serviceName = $service->name;
            if (! isset($compose['services'][$serviceName]['volumes'])) {
                return false;
            }

            $volumes = $compose['services'][$serviceName]['volumes'];

            // Check each volume to find a match
            // Note: We match on mount_path (container path) only, since fs_path gets transformed
            // from relative (./file) to absolute (/data/coolify/services/uuid/file) during parsing
            foreach ($volumes as $volume) {
                // Volume can be string like "host:container:ro" or "host:container"
                if (is_string($volume)) {
                    $parts = explode(':', $volume);

                    // Check if this volume matches our mount_path
                    if (count($parts) >= 2) {
                        $containerPath = $parts[1];
                        $options = $parts[2] ?? null;

                        // Match based on mount_path
                        // Remove leading slash from mount_path if present for comparison
                        $mountPath = str($this->mount_path)->ltrim('/')->toString();
                        $containerPathClean = str($containerPath)->ltrim('/')->toString();

                        if ($mountPath === $containerPathClean || $this->mount_path === $containerPath) {
                            return $options === 'ro';
                        }
                    }
                } elseif (is_array($volume)) {
                    // Long-form syntax: { type: bind, source: ..., target: ..., read_only: true }
                    $containerPath = data_get($volume, 'target');
                    $readOnly = data_get($volume, 'read_only', false);

                    // Match based on mount_path
                    // Remove leading slash from mount_path if present for comparison
                    $mountPath = str($this->mount_path)->ltrim('/')->toString();
                    $containerPathClean = str($containerPath)->ltrim('/')->toString();

                    if ($mountPath === $containerPathClean || $this->mount_path === $containerPath) {
                        return $readOnly === true;
                    }
                }
            }

            return false;
        } catch (\Throwable $e) {

            return false;
        }
    }
}
