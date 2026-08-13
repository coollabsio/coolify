<?php

namespace App\Services\Migration\Storage;

use App\Models\Server;
use RuntimeException;

class GcsDriver implements MigrationStorageDriver
{
    /**
     * @param  array{bucket?: string, credentials?: string, project_id?: string}  $config
     */
    public function __construct(private array $config) {}

    public function put(Server $server, string $localPath, string $objectKey): array
    {
        $this->runGcloud($server, [
            'gcloud storage cp '.escapeshellarg($localPath).' '.escapeshellarg($this->objectUri($objectKey)),
        ], $localPath);

        $size = (int) instant_remote_process(
            ['du -b '.escapeshellarg($localPath).' | cut -f1'],
            $server,
            throwError: false,
        );

        return [
            'driver' => 'gcs',
            'bucket' => $this->bucket(),
            'key' => $objectKey,
            'checksum' => null,
            'size_bytes' => $size,
        ];
    }

    public function get(Server $server, string $objectKey, string $destinationPath): string
    {
        $this->runGcloud($server, [
            'mkdir -p '.escapeshellarg(dirname($destinationPath)),
            'gcloud storage cp '.escapeshellarg($this->objectUri($objectKey)).' '.escapeshellarg($destinationPath),
        ]);

        return $destinationPath;
    }

    public function exists(Server $server, string $objectKey): bool
    {
        try {
            $this->runGcloud($server, [
                'gcloud storage ls '.escapeshellarg($this->objectUri($objectKey)).' >/dev/null',
            ]);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function delete(Server $server, string $objectKey): void
    {
        try {
            $this->runGcloud($server, [
                'gcloud storage rm '.escapeshellarg($this->objectUri($objectKey)),
            ]);
        } catch (RuntimeException) {
            // Ignore missing objects during cleanup.
        }
    }

    public function diskFree(Server $server): ?int
    {
        $output = instant_remote_process(
            ['df -B1 --output=avail / | tail -1'],
            $server,
            throwError: false,
        );

        return is_numeric(trim((string) $output)) ? (int) trim((string) $output) : null;
    }

    /**
     * @param  array<int, string>  $commands
     */
    private function runGcloud(Server $server, array $commands, ?string $bindPath = null): void
    {
        $this->assertConfigured();
        $container = 'migration-gcs-'.uniqid();
        $image = escapeshellarg(coolifyHelperImage().':'.getHelperVersion());
        $volume = $bindPath
            ? ' -v '.escapeshellarg($bindPath.':'.$bindPath)
            : ' -v '.escapeshellarg('/data/coolify/backups:/data/coolify/backups');

        $wrapped = array_map(
            fn (string $command): string => str_starts_with($command, 'gcloud ')
                ? 'docker exec '.escapeshellarg($container).' '.$command
                : $command,
            $commands,
        );

        try {
            instant_remote_process([
                'docker rm -f '.escapeshellarg($container).' >/dev/null 2>&1 || true',
                'docker run -d --name '.escapeshellarg($container).' --rm'.$volume.' '.$image,
                ...$wrapped,
            ], $server);
        } finally {
            instant_remote_process(
                ['docker rm -f '.escapeshellarg($container)],
                $server,
                throwError: false,
            );
        }
    }

    private function objectUri(string $objectKey): string
    {
        return 'gs://'.$this->bucket().'/'.ltrim($objectKey, '/');
    }

    private function bucket(): string
    {
        return (string) ($this->config['bucket'] ?? '');
    }

    private function assertConfigured(): void
    {
        if ($this->bucket() === '') {
            throw new RuntimeException('GCS storage requires a bucket.');
        }
    }
}
