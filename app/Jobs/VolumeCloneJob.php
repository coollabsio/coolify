<?php

namespace App\Jobs;

use App\Models\LocalPersistentVolume;
use App\Models\Server;
use App\Traits\StagesCloneArchives;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class VolumeCloneJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, StagesCloneArchives;

    public int $timeout = 3600;

    public function __construct(
        protected string $sourceVolume,
        protected string $targetVolume,
        protected Server $sourceServer,
        protected ?Server $targetServer,
        protected LocalPersistentVolume $persistentVolume
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        try {
            if (! $this->targetServer || $this->targetServer->id === $this->sourceServer->id) {
                $this->cloneLocalVolume();
            } else {
                $this->cloneRemoteVolume();
            }
        } catch (\Exception $e) {
            \Log::error("Failed to copy volume data for {$this->sourceVolume}: ".$e->getMessage());
            throw $e;
        }
    }

    protected function cloneLocalVolume(): void
    {
        $srcVol = escapeshellarg($this->sourceVolume);
        $tgtVol = escapeshellarg($this->targetVolume);

        // Same-name local copy is a no-op (used when only the network destination changes).
        if ($this->sourceVolume === $this->targetVolume) {
            return;
        }

        $sourceConfiguration = $this->sourceVolumeConfiguration();
        $isBindVolume = $this->isBindVolume($sourceConfiguration);
        $targetConfiguration = $isBindVolume ? ['driver' => 'local', 'options' => []] : $sourceConfiguration;
        $createTargetVolume = $this->createTargetVolumeCommand($targetConfiguration);

        if ($isBindVolume) {
            instant_remote_process([$createTargetVolume], $this->sourceServer);
            $this->rememberTargetVolumeWithoutDriverOptions($targetConfiguration);
            $this->logSkippedBindVolumeCopy($sourceConfiguration);

            return;
        }

        instant_remote_process([
            $createTargetVolume,
            "docker run --rm -v {$srcVol}:/source -v {$tgtVol}:/target alpine sh -c 'cp -a /source/. /target/ && chown -R 1000:1000 /target'",
        ], $this->sourceServer);
        $this->rememberTargetVolumeWithoutDriverOptions($sourceConfiguration);
    }

    protected function cloneRemoteVolume(): void
    {
        $srcVol = escapeshellarg($this->sourceVolume);
        $tgtVol = escapeshellarg($this->targetVolume);
        $sourceConfiguration = $this->sourceVolumeConfiguration();
        $isBindVolume = $this->isBindVolume($sourceConfiguration);
        $targetConfiguration = $isBindVolume ? ['driver' => 'local', 'options' => []] : $sourceConfiguration;
        $createTargetVolume = $this->createTargetVolumeCommand($targetConfiguration);

        if ($isBindVolume) {
            instant_remote_process([$createTargetVolume], $this->targetServer);
            $this->rememberTargetVolumeWithoutDriverOptions($targetConfiguration);
            $this->logSkippedBindVolumeCopy($sourceConfiguration);

            return;
        }

        $sourceCloneDir = null;
        $targetCloneDir = null;
        $localTempDir = storage_path('app/tmp/volume-clones/'.Str::uuid()->toString());
        $localArchive = $localTempDir.'/volume-data.tar.gz';

        try {
            File::ensureDirectoryExists($localTempDir, 0755);

            $sourceCloneDir = $this->createCloneArchiveDirectory($this->sourceServer, $this->sourceVolume);
            $srcDir = escapeshellarg($sourceCloneDir);
            instant_remote_process([
                "docker run --rm -v {$srcVol}:/source -v {$srcDir}:/clone alpine sh -c 'cd /source && tar czf /clone/volume-data.tar.gz .'",
            ], $this->sourceServer);

            $targetCloneDir = $this->createCloneArchiveDirectory($this->targetServer, $this->targetVolume);
            $tgtDir = escapeshellarg($targetCloneDir);

            // Coolify host is the intermediary: download from source, upload to target.
            instant_scp_from_server(
                "{$sourceCloneDir}/volume-data.tar.gz",
                $localArchive,
                $this->sourceServer
            );

            instant_scp(
                $localArchive,
                "{$targetCloneDir}/volume-data.tar.gz",
                $this->targetServer
            );

            instant_remote_process([
                $createTargetVolume,
                "docker run --rm -v {$tgtVol}:/target -v {$tgtDir}:/clone alpine sh -c 'cd /target && tar xzf /clone/volume-data.tar.gz && chown -R 1000:1000 /target'",
            ], $this->targetServer);
            $this->rememberTargetVolumeWithoutDriverOptions($sourceConfiguration);
        } catch (\Exception $e) {
            \Log::error("Failed to clone volume {$this->sourceVolume} to {$this->targetVolume}: ".$e->getMessage());
            throw $e;
        } finally {
            try {
                File::deleteDirectory($localTempDir);
            } catch (\Exception $e) {
                \Log::warning('Failed to clean up local volume clone directory: '.$e->getMessage());
            }

            $this->removeCloneArchiveDirectory($this->sourceServer, $sourceCloneDir);
            $this->removeCloneArchiveDirectory($this->targetServer, $targetCloneDir);
        }
    }

    /**
     * The driver and driver options of the source volume. A missing source volume gives a plain
     * `local` volume, as Docker creates an empty source volume for the copy.
     *
     * @return array{driver: string, options: array<string, string>}
     */
    protected function sourceVolumeConfiguration(): array
    {
        $output = instant_remote_process([
            'docker volume inspect '.escapeshellarg($this->sourceVolume)." 2>/dev/null || echo '[]'",
        ], $this->sourceServer);
        $inspected = json_decode((string) $output, true);
        $volume = is_array($inspected) && is_array($inspected[0] ?? null) ? $inspected[0] : [];

        $driver = data_get($volume, 'Driver');
        $options = [];
        foreach ((array) data_get($volume, 'Options', []) as $key => $value) {
            if (is_string($key) && $key !== '' && is_scalar($value)) {
                $options[$key] = (string) $value;
            }
        }

        return [
            'driver' => is_string($driver) && $driver !== '' ? $driver : 'local',
            'options' => $options,
        ];
    }

    /**
     * Creates the target volume with its selected driver and options. A bind clone uses a plain local
     * volume and a name-only Compose declaration, so it never mounts the source host directory.
     * One `sh -c` line, so the non-root sudo parser only puts sudo in front of it and never changes
     * an option value.
     *
     * @param  array{driver: string, options: array<string, string>}  $configuration
     */
    protected function createTargetVolumeCommand(array $configuration): string
    {
        $command = 'docker volume create';
        if ($configuration['driver'] !== 'local') {
            $command .= ' --driver '.escapeshellarg($configuration['driver']);
        }
        foreach ($configuration['options'] as $key => $value) {
            $command .= ' --opt '.escapeshellarg("{$key}={$value}");
        }
        $command .= ' '.escapeshellarg($this->targetVolume);

        return 'sh -c '.escapeshellarg($command);
    }

    /**
     * A `local` volume with `type: none` and `o: bind` is a host folder. Copying the data would write
     * into that folder (on the same server, the folder of the source volume itself), so the clone gets
     * an empty Docker-managed volume without copying the host folder.
     *
     * @param  array{driver: string, options: array<string, string>}  $configuration
     */
    protected function isBindVolume(array $configuration): bool
    {
        return $configuration['driver'] === 'local'
            && ($configuration['options']['type'] ?? null) === 'none'
            && in_array('bind', array_map('trim', explode(',', $configuration['options']['o'] ?? '')), true);
    }

    /**
     * @param  array{driver: string, options: array<string, string>}  $configuration
     */
    protected function logSkippedBindVolumeCopy(array $configuration): void
    {
        $device = $configuration['options']['device'] ?? 'unknown';
        \Log::info("Volume {$this->sourceVolume} is a bind mount of the host folder {$device}. Created independent empty volume {$this->targetVolume} and did not copy the data.");
    }

    /**
     * A target volume without driver options gets the name-only Compose declaration, like any volume
     * that Docker created without the options (see composeRenamedVolumeDeclarationFor()).
     *
     * @param  array{driver: string, options: array<string, string>}  $configuration
     */
    protected function rememberTargetVolumeWithoutDriverOptions(array $configuration): void
    {
        if ($configuration['driver'] !== 'local' || $configuration['options'] !== []) {
            return;
        }
        if ($this->persistentVolume->name !== $this->targetVolume || $this->persistentVolume->ignores_compose_driver_options) {
            return;
        }

        $this->persistentVolume->forceFill(['ignores_compose_driver_options' => true])->saveQuietly();
    }
}
