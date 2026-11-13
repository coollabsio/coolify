<?php

namespace App\Livewire\ImageScan;

use App\Actions\ImageScan\ScanImage;
use App\Actions\ImageScan\SetServerImageScanning;
use App\Jobs\PrepareImageScannerJob;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Services\ImageScan\ImageRef;
use App\Services\ImageScan\ImageScanningRefused;
use App\Services\ImageScan\ScannerCommands;
use App\Traits\ListensToTeamChannel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class ServerImageScanning extends Component
{
    use AuthorizesRequests;
    use ListensToTeamChannel;

    public Server $server;

    public string $scannerMemoryMb = '';

    public string $scannerSyftTimeout = '';

    public string $scannerGrypeTimeout = '';

    public string $scannerCpus = '';

    public function getListeners()
    {
        return $this->teamChannelListeners([
            'ImageScanUpdated' => 'scanUpdated',
        ]);
    }

    public function mount(string $server_uuid)
    {
        $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('viewSecurity', $this->server);
        $this->fillScannerLimits();
    }

    /**
     * Scanner limits: Livewire property => [settings column, allowed values, audit action, saved message].
     *
     * @return array<string, array{0: string, 1: list<string>, 2: string, 3: string}>
     */
    private function scannerLimits(): array
    {
        $strings = fn (array $values): array => array_map('strval', $values);

        return [
            'scannerMemoryMb' => ['image_scan_memory_mb', $strings(ScannerCommands::memoryOptionsMb($this->server)), 'image_scanner_memory_changed', 'Scanner memory limit saved. It applies to the next scan.'],
            'scannerSyftTimeout' => ['image_scan_syft_timeout', $strings(ServerSetting::IMAGE_SCAN_TIMEOUT_OPTIONS_SECONDS), 'image_scanner_syft_timeout_changed', 'Syft time limit saved. It applies to the next scan.'],
            'scannerGrypeTimeout' => ['image_scan_grype_timeout', $strings(ServerSetting::IMAGE_SCAN_TIMEOUT_OPTIONS_SECONDS), 'image_scanner_grype_timeout_changed', 'Grype time limit saved. It applies to the next scan.'],
            'scannerCpus' => ['image_scan_cpus', $strings(ScannerCommands::cpuOptions($this->server)), 'image_scanner_cpus_changed', 'Scanner CPU limit saved. It applies to the next scan.'],
        ];
    }

    /**
     * Listbox options for the values that fit the server. A saved value that no longer fits stays in the list, marked,
     * so the field does not show an empty value; it cannot be saved again.
     *
     * @param  list<int|float>  $allowed
     * @return list<array{value: string, label: string}>
     */
    private static function listboxOptions(array $allowed, int|float $current, \Closure $label): array
    {
        $values = in_array($current, $allowed) ? $allowed : [...$allowed, $current];
        sort($values);

        return array_map(fn (int|float $value): array => [
            'value' => (string) $value,
            'label' => $label($value).(in_array($value, $allowed) ? '' : ' (more than this server has)'),
        ], $values);
    }

    private function fillScannerLimits(): void
    {
        foreach ($this->scannerLimits() as $property => [$column]) {
            $this->{$property} = (string) $this->server->settings->{$column};
        }
    }

    public function updatedScannerMemoryMb()
    {
        return $this->saveScannerLimit('scannerMemoryMb');
    }

    public function updatedScannerSyftTimeout()
    {
        return $this->saveScannerLimit('scannerSyftTimeout');
    }

    public function updatedScannerGrypeTimeout()
    {
        return $this->saveScannerLimit('scannerGrypeTimeout');
    }

    public function updatedScannerCpus()
    {
        return $this->saveScannerLimit('scannerCpus');
    }

    private function saveScannerLimit(string $property)
    {
        [$column, $options, $action, $message] = $this->scannerLimits()[$property];
        $this->authorize('update', $this->server);

        $this->validate(
            [$property => ['required', Rule::in($options)]],
            [$property.'.in' => 'Choose one of the listed values. Values that are more than this server has are not allowed.'],
        );

        try {
            SetServerImageScanning::ensureManagedHere($this->server);
            $this->server->settings->update([$column => $this->{$property}]);
            $this->auditImageScanning($action);
            $this->dispatch('success', $message);
        } catch (\Throwable $e) {
            $this->server->settings->refresh();
            $this->fillScannerLimits();

            return handleError($e, $this);
        }
    }

    public function scanUpdated(array $event = []): void
    {
        $resourceUuid = data_get($event, 'resourceUuid');
        if ($resourceUuid !== $this->server->uuid || data_get($event, 'scanUuid') !== PrepareImageScannerJob::SCAN_UUID) {
            $this->skipRender();
        }
    }

    public function enable()
    {
        $this->authorize('update', $this->server);

        try {
            SetServerImageScanning::run($this->server, true);
            $this->auditImageScanning('image_scanning_enabled');
            $this->dispatch('success', 'Image scanning turned on. Coolify is getting the scanner.');
        } catch (ImageScanningRefused $e) {
            $this->dispatch('error', message: $e->getMessage());
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function prepareScanner()
    {
        $this->authorize('update', $this->server);

        try {
            SetServerImageScanning::ensureManagedHere($this->server);
            if (! $this->server->settings->refresh()->is_image_scan_enabled) {
                $this->dispatch('error', message: 'Turn on image scanning first.');

                return;
            }

            SetServerImageScanning::prepareScanner($this->server);
            $this->dispatch('success', 'Coolify is getting the scanner.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function disable()
    {
        $this->authorize('update', $this->server);

        try {
            SetServerImageScanning::run($this->server, false);
            $this->auditImageScanning('image_scanning_disabled');
            $this->dispatch('success', 'Image scanning turned off.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function deleteScannerData()
    {
        $this->authorize('update', $this->server);

        $lock = ScanImage::serverLock($this->server);
        try {
            SetServerImageScanning::ensureManagedHere($this->server);
            if (! ScanImage::acquireServerLock($lock, $this->server, 0)) {
                $this->dispatch('error', message: 'A scan or a scanner update is running on this server. Try again when it is complete.');

                return;
            }
            $commands = new ScannerCommands($this->server, Str::lower(Str::random(24)));
            instant_remote_process($commands->deleteScannerData(), $this->server, throwError: true, timeout: 120);
            PrepareImageScannerJob::forget($this->server);
            // Records the server as empty, so the page can hide the delete action until the data comes back.
            Cache::put(PrepareImageScannerJob::diskUsedCacheKey($this->server), 0, now()->addDays(7));
            $this->auditImageScanning('image_scanner_data_deleted');
            $this->dispatch('success', 'Scanner data deleted.');
        } catch (\Throwable $e) {
            return handleError($e, $this, 'Could not delete the scanner data.');
        } finally {
            $lock->release();
        }
    }

    private function auditImageScanning(string $action): void
    {
        auditLog("ui.server.{$action}", [
            'team_id' => $this->server->team_id,
            'server_uuid' => $this->server->uuid,
            'server_name' => $this->server->name,
        ]);
    }

    public function render()
    {
        $state = Cache::get(PrepareImageScannerJob::stateCacheKey($this->server));
        $builtAt = Cache::get(PrepareImageScannerJob::builtAtCacheKey($this->server));
        $failedStep = Cache::get(PrepareImageScannerJob::failedStepCacheKey($this->server));
        $diskUsed = PrepareImageScannerJob::diskUsedBytes($this->server);

        return view('livewire.image-scan.server-image-scanning', [
            'enabled' => (bool) $this->server->settings->is_image_scan_enabled,
            'memoryLimit' => formatBytes($this->server->settings->image_scan_memory_mb * 1024 * 1024, 0),
            'memoryOptions' => self::listboxOptions(
                ScannerCommands::memoryOptionsMb($this->server),
                (int) $this->server->settings->image_scan_memory_mb,
                fn (int $mb): string => formatBytes($mb * 1024 * 1024, 0),
            ),
            'timeoutOptions' => array_map(
                fn (int $seconds) => ['value' => (string) $seconds, 'label' => ($seconds / 60).' minutes'],
                ServerSetting::IMAGE_SCAN_TIMEOUT_OPTIONS_SECONDS,
            ),
            'cpuOptions' => self::listboxOptions(
                ScannerCommands::cpuOptions($this->server),
                (float) $this->server->settings->image_scan_cpus,
                fn (float $cpus): string => $cpus.' '.str('CPU')->plural($cpus > 1 ? 2 : 1),
            ),
            'prepareState' => is_string($state) ? $state : null,
            'failedStep' => is_string($failedStep) ? $failedStep : null,
            'registries' => collect(config('constants.image_scan.images'))
                ->map(fn (array $image) => ImageRef::parse($image['repo'])->registry)
                ->unique()
                ->implode(', '),
            'diskUsed' => $diskUsed !== null ? formatBytes($diskUsed, 1) : null,
            // Hide the delete action only when the server is known to be empty. Missing cache data can still mean data on disk.
            'hasScannerData' => is_string($state) || $diskUsed !== 0,
            'toolVersions' => collect(['syft' => 'Syft', 'grype' => 'Grype', 'regctl' => 'regctl'])
                ->map(fn (string $name, string $tool) => $name.' '.config("constants.image_scan.images.{$tool}.version"))
                ->implode(', '),
            'builtAt' => is_string($builtAt) ? CarbonImmutable::parse($builtAt) : null,
            'cpuLimit' => ScannerCommands::cpus($this->server),
        ]);
    }
}
