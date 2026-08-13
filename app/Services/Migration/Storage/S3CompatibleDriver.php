<?php

namespace App\Services\Migration\Storage;

use App\Models\Server;
use App\Rules\SafeWebhookUrl;
use RuntimeException;

class S3CompatibleDriver implements MigrationStorageDriver
{
    /**
     * @param  array{endpoint?: string, bucket?: string, region?: string, key?: string, secret?: string}  $config
     */
    public function __construct(private array $config) {}

    public function put(Server $server, string $localPath, string $objectKey): array
    {
        $this->assertConfigured();
        $this->runMc($server, [
            'mc cp '.escapeshellarg($localPath).' '.escapeshellarg($this->objectUri($objectKey)),
        ], $localPath);

        $size = $this->fileSize($server, $localPath);
        $checksum = $this->checksum($server, $localPath);

        return [
            'driver' => 's3',
            'bucket' => $this->bucket(),
            'key' => $objectKey,
            'checksum' => $checksum,
            'size_bytes' => $size,
        ];
    }

    public function get(Server $server, string $objectKey, string $destinationPath): string
    {
        $this->assertConfigured();
        $directory = dirname($destinationPath);
        $this->runMc($server, [
            'mkdir -p '.escapeshellarg($directory),
            'mc cp '.escapeshellarg($this->objectUri($objectKey)).' '.escapeshellarg($destinationPath),
        ]);

        return $destinationPath;
    }

    public function exists(Server $server, string $objectKey): bool
    {
        try {
            $this->runMc($server, [
                'mc stat '.escapeshellarg($this->objectUri($objectKey)).' >/dev/null',
            ]);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function delete(Server $server, string $objectKey): void
    {
        try {
            $this->runMc($server, [
                'mc rm '.escapeshellarg($this->objectUri($objectKey)),
            ]);
        } catch (RuntimeException) {
            // Missing objects are not an error during cleanup.
        }
    }

    public function diskFree(Server $server): ?int
    {
        $output = instant_remote_process(
            ['df -B1 --output=avail / | tail -1'],
            $server,
            throwError: false,
        );

        if (! is_numeric(trim((string) $output))) {
            return null;
        }

        return (int) trim((string) $output);
    }

    /**
     * @param  array<int, string>  $commands
     */
    private function runMc(Server $server, array $commands, ?string $bindPath = null): void
    {
        $container = 'migration-s3-'.uniqid();
        $image = escapeshellarg(coolifyHelperImage().':'.getHelperVersion());
        $volume = $bindPath
            ? ' -v '.escapeshellarg($bindPath.':'.$bindPath)
            : ' -v '.escapeshellarg('/data/coolify/backups:/data/coolify/backups');

        $resolveOptions = collect(SafeWebhookUrl::minioClientResolveOptions($this->endpoint()))
            ->map(fn (string $option): string => '--resolve '.escapeshellarg($option))
            ->implode(' ');
        $resolveOptions = $resolveOptions === '' ? '' : ' '.$resolveOptions;

        $alias = 'docker exec '.escapeshellarg($container).' mc alias set'.$resolveOptions.' temporary '
            .escapeshellarg($this->endpoint()).' '.escapeshellarg($this->key()).' '.escapeshellarg($this->secret());

        $wrapped = array_map(
            fn (string $command): string => str_starts_with($command, 'mc ')
                ? 'docker exec '.escapeshellarg($container).' '.$command
                : $command,
            $commands,
        );

        try {
            instant_remote_process([
                'docker rm -f '.escapeshellarg($container).' >/dev/null 2>&1 || true',
                'docker run -d --name '.escapeshellarg($container).' --rm'.$volume.' '.$image,
                $alias,
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
        return 'temporary/'.$this->bucket().'/'.ltrim($objectKey, '/');
    }

    private function fileSize(Server $server, string $path): int
    {
        return (int) instant_remote_process(
            ['du -b '.escapeshellarg($path).' | cut -f1'],
            $server,
            throwError: false,
        );
    }

    private function checksum(Server $server, string $path): ?string
    {
        $output = instant_remote_process(
            ['sha256sum '.escapeshellarg($path).' | awk \'{print $1}\''],
            $server,
            throwError: false,
        );

        $checksum = trim((string) $output);

        return $checksum !== '' ? $checksum : null;
    }

    private function assertConfigured(): void
    {
        foreach (['endpoint', 'bucket', 'key', 'secret'] as $field) {
            if (blank($this->config[$field] ?? null)) {
                throw new RuntimeException("S3 storage is missing {$field}.");
            }
        }
    }

    private function endpoint(): string
    {
        return (string) $this->config['endpoint'];
    }

    private function bucket(): string
    {
        return (string) $this->config['bucket'];
    }

    private function key(): string
    {
        return (string) $this->config['key'];
    }

    private function secret(): string
    {
        return (string) $this->config['secret'];
    }
}
