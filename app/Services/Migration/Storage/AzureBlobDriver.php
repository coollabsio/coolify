<?php

namespace App\Services\Migration\Storage;

use App\Models\Server;
use RuntimeException;

class AzureBlobDriver implements MigrationStorageDriver
{
    /**
     * @param  array{account?: string, container?: string, key?: string, sas?: string}  $config
     */
    public function __construct(private array $config) {}

    public function put(Server $server, string $localPath, string $objectKey): array
    {
        $this->runAzCopy($server, [
            'azcopy copy '.escapeshellarg($localPath).' '.escapeshellarg($this->blobUrl($objectKey)),
        ], $localPath);

        $size = (int) instant_remote_process(
            ['du -b '.escapeshellarg($localPath).' | cut -f1'],
            $server,
            throwError: false,
        );

        return [
            'driver' => 'azure',
            'bucket' => $this->container(),
            'key' => $objectKey,
            'checksum' => null,
            'size_bytes' => $size,
        ];
    }

    public function get(Server $server, string $objectKey, string $destinationPath): string
    {
        $this->runAzCopy($server, [
            'mkdir -p '.escapeshellarg(dirname($destinationPath)),
            'azcopy copy '.escapeshellarg($this->blobUrl($objectKey)).' '.escapeshellarg($destinationPath),
        ]);

        return $destinationPath;
    }

    public function exists(Server $server, string $objectKey): bool
    {
        try {
            $this->runAzCopy($server, [
                'azcopy list '.escapeshellarg($this->blobUrl($objectKey)).' >/dev/null',
            ]);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function delete(Server $server, string $objectKey): void
    {
        try {
            $this->runAzCopy($server, [
                'azcopy remove '.escapeshellarg($this->blobUrl($objectKey)),
            ]);
        } catch (RuntimeException) {
            // Ignore missing blobs during cleanup.
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
    private function runAzCopy(Server $server, array $commands, ?string $bindPath = null): void
    {
        $this->assertConfigured();
        $container = 'migration-azure-'.uniqid();
        $image = escapeshellarg(coolifyHelperImage().':'.getHelperVersion());
        $volume = $bindPath
            ? ' -v '.escapeshellarg($bindPath.':'.$bindPath)
            : ' -v '.escapeshellarg('/data/coolify/backups:/data/coolify/backups');

        $env = ' -e AZCOPY_AUTO_LOGIN_TYPE=SPN';
        if (filled($this->config['key'] ?? null)) {
            $env = ' -e AZURE_STORAGE_ACCOUNT='.escapeshellarg((string) $this->config['account'])
                .' -e AZURE_STORAGE_KEY='.escapeshellarg((string) $this->config['key']);
        }

        $wrapped = array_map(
            fn (string $command): string => str_starts_with($command, 'azcopy ')
                ? 'docker exec '.escapeshellarg($container).' '.$command
                : $command,
            $commands,
        );

        try {
            instant_remote_process([
                'docker rm -f '.escapeshellarg($container).' >/dev/null 2>&1 || true',
                'docker run -d --name '.escapeshellarg($container).' --rm'.$volume.$env.' '.$image,
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

    private function blobUrl(string $objectKey): string
    {
        $base = sprintf(
            'https://%s.blob.core.windows.net/%s/%s',
            $this->config['account'],
            $this->container(),
            ltrim($objectKey, '/'),
        );

        if (filled($this->config['sas'] ?? null)) {
            return $base.'?'.$this->config['sas'];
        }

        return $base;
    }

    private function container(): string
    {
        return (string) ($this->config['container'] ?? $this->config['bucket'] ?? '');
    }

    private function assertConfigured(): void
    {
        if (blank($this->config['account'] ?? null) || $this->container() === '') {
            throw new RuntimeException('Azure Blob storage requires account and container.');
        }
    }
}
