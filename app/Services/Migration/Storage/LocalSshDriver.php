<?php

namespace App\Services\Migration\Storage;

use App\Models\Server;
use RuntimeException;

class LocalSshDriver implements MigrationStorageDriver
{
    /**
     * @param  array{base_path?: string}  $config
     */
    public function __construct(private array $config = []) {}

    public function put(Server $server, string $localPath, string $objectKey): array
    {
        $destination = $this->objectPath($objectKey);
        $this->ensureDirectory($destination);

        if ($localPath !== $destination && ! copy($localPath, $destination)) {
            throw new RuntimeException("Failed to store local migration archive {$objectKey}.");
        }

        if (! is_file($destination) || filesize($destination) === 0) {
            throw new RuntimeException("Local migration archive {$objectKey} is empty or missing.");
        }

        return [
            'driver' => 'local-ssh',
            'bucket' => null,
            'key' => $objectKey,
            'checksum' => hash_file('sha256', $destination) ?: null,
            'size_bytes' => (int) filesize($destination),
        ];
    }

    public function get(Server $server, string $objectKey, string $destinationPath): string
    {
        $source = $this->objectPath($objectKey);
        if (! is_file($source)) {
            throw new RuntimeException("Local migration archive {$objectKey} was not found.");
        }

        $this->ensureDirectory($destinationPath);
        if (! copy($source, $destinationPath)) {
            throw new RuntimeException("Failed to read local migration archive {$objectKey}.");
        }

        return $destinationPath;
    }

    public function exists(Server $server, string $objectKey): bool
    {
        return is_file($this->objectPath($objectKey));
    }

    public function delete(Server $server, string $objectKey): void
    {
        $path = $this->objectPath($objectKey);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function diskFree(Server $server): ?int
    {
        $path = $this->basePath();
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }

        $free = disk_free_space($path);

        return $free === false ? null : (int) $free;
    }

    public function objectPath(string $objectKey): string
    {
        return $this->basePath().'/'.ltrim($objectKey, '/');
    }

    private function ensureDirectory(string $path): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Failed to create directory {$directory}.");
        }
    }

    private function basePath(): string
    {
        return $this->config['base_path'] ?? (function_exists('storage_path') && function_exists('app') && app()->bound('path.storage')
            ? storage_path('app/tmp/migrations')
            : sys_get_temp_dir().'/coolify-migrations');
    }
}
