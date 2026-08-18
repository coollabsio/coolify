<?php

namespace App\Models;

use App\Events\FileStorageChanged;
use App\Jobs\ServerStorageSaveJob;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Stringable;
use Symfony\Component\Yaml\Yaml;

class LocalFileVolume extends BaseModel
{
    public const MAX_CONTENT_SIZE = 5_242_880;

    public const BINARY_PLACEHOLDER = '[binary file]';

    public const TOO_LARGE_PLACEHOLDER = '[file too large to display]';

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
        'chown',
        'chmod',
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

            $fileVolume->load(['service']);
            dispatch(new ServerStorageSaveJob($fileVolume));
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
            ["stat -c%s {$escapedPath} 2>/dev/null || wc -c < {$escapedPath}"],
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

    public function deleteStorageOnServer()
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

    public function saveStorageOnServer()
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
        $escapedWorkdir = escapeshellarg($workdir);

        if ($this->is_directory) {
            // Validate fs_path early before any shell interpolation
            validateShellSafePath($this->fs_path, 'storage path');
            $escapedFsPath = escapeshellarg($this->fs_path);
            $commands->push("mkdir -p {$escapedFsPath} > /dev/null 2>&1 || true");
            $commands->push("mkdir -p {$escapedWorkdir} > /dev/null 2>&1 || true");
            $commands->push("cd {$escapedWorkdir}");
        }
        $path = data_get_str($this, 'fs_path');
        $content = data_get($this, 'content');
        $pathForParentDirectory = str($this->fs_path);
        if ($pathForParentDirectory->startsWith('.') || $pathForParentDirectory->startsWith('/') || $pathForParentDirectory->startsWith('~')) {
            $parent_dir = $pathForParentDirectory->beforeLast('/');
            if ($parent_dir != '') {
                $escapedParentDir = escapeshellarg($parent_dir);
                $commands->push("mkdir -p {$escapedParentDir} > /dev/null 2>&1 || true");
            }
        }
        if ($path->startsWith('.')) {
            $path = $path->after('.');
            $path = $workdir.$path;
        }

        // Validate and escape resolved path (may differ from fs_path if relative)
        validateShellSafePath($path, 'storage path');
        $escapedPath = escapeshellarg($path);

        $isFile = instant_remote_process(["test -f {$escapedPath} && echo OK || echo NOK"], $server);
        $isDir = instant_remote_process(["test -d {$escapedPath} && echo OK || echo NOK"], $server);
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
            instant_remote_process([
                "rm -fr {$escapedPath}",
                "touch {$escapedPath}",
            ], $server, false);
            FileStorageChanged::dispatch(data_get($server, 'team_id'));
        }
        if ($isDir === 'NOK' && ! $this->is_directory) {
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
                $commands->push("chown $chown {$escapedPath}");
            }
            if ($chmod) {
                $commands->push("chmod $chmod {$escapedPath}");
            }
        } elseif ($isDir === 'NOK' && $this->is_directory) {
            $commands->push("mkdir -p {$escapedPath} > /dev/null 2>&1 || true");
        }

        return instant_remote_process($commands, $server);
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

    public function isReadOnlyVolume(): bool
    {
        try {
            $resource = $this->service;
            if (! $resource) {
                return false;
            }

            if ($this->isServiceResource()) {
                $composeResource = $resource->service;
                if (! $composeResource) {
                    return false;
                }

                $baseDirectory = (int) $composeResource->compose_parsing_version >= 4
                    ? 'services'
                    : 'applications';
                $mainDirectory = str(base_configuration_dir()."/{$baseDirectory}/{$composeResource->uuid}");
                $services = [data_get(Yaml::parse($composeResource->docker_compose_raw), "services.{$resource->name}")];
            } else {
                $composeResource = $resource;
                $mainDirectory = str(base_configuration_dir()."/applications/{$composeResource->uuid}");
                $services = data_get(Yaml::parse($composeResource->docker_compose_raw), 'services', []);
            }

            foreach ($services as $service) {
                foreach (data_get($service, 'volumes', []) as $volume) {
                    if (is_string($volume)) {
                        $parts = explode(':', $volume);
                        if (count($parts) < 2) {
                            continue;
                        }

                        if ($this->matchesComposeVolume($parts[0], $parts[1], $mainDirectory)) {
                            $options = array_map('trim', explode(',', $parts[2] ?? ''));

                            return in_array('ro', $options, true);
                        }
                    } elseif (is_array($volume)) {
                        $source = data_get($volume, 'source');
                        $target = data_get($volume, 'target');

                        if ($source !== null && $target !== null && $this->matchesComposeVolume($source, $target, $mainDirectory)) {
                            return (bool) data_get($volume, 'read_only', false);
                        }
                    }
                }
            }

            return false;
        } catch (\Throwable $e) {

            return false;
        }
    }

    private function matchesComposeVolume(string $source, string $target, Stringable $mainDirectory): bool
    {
        if (str($target)->ltrim('/')->value() !== str($this->mount_path)->ltrim('/')->value()) {
            return false;
        }

        return replaceLocalSource(str($source), $mainDirectory)->value()
            === replaceLocalSource(str($this->fs_path), $mainDirectory)->value();
    }
}
