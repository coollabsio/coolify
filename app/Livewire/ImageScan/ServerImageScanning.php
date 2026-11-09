<?php

namespace App\Livewire\ImageScan;

use App\Actions\ImageScan\ScanImage;
use App\Actions\ImageScan\SetServerImageScanning;
use App\Jobs\PrepareImageScannerJob;
use App\Models\Server;
use App\Services\ImageScan\ImageRef;
use App\Services\ImageScan\ImageScanningRefused;
use App\Services\ImageScan\ScannerCommands;
use App\Traits\ListensToTeamChannel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Component;

class ServerImageScanning extends Component
{
    use AuthorizesRequests;
    use ListensToTeamChannel;

    public Server $server;

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
        $diskUsed = Cache::get(PrepareImageScannerJob::diskUsedCacheKey($this->server));

        return view('livewire.image-scan.server-image-scanning', [
            'enabled' => (bool) $this->server->settings->is_image_scan_enabled,
            'prepareState' => is_string($state) ? $state : null,
            'failedStep' => is_string($failedStep) ? $failedStep : null,
            'registries' => collect(config('constants.image_scan.images'))
                ->map(fn (array $image) => ImageRef::parse($image['repo'])->registry)
                ->unique()
                ->implode(', '),
            'diskUsed' => is_int($diskUsed) ? formatBytes($diskUsed, 1) : null,
            // Hide the delete action only when the server is known to be empty. Missing cache data can still mean data on disk.
            'hasScannerData' => is_string($state) || $diskUsed !== 0,
            'toolVersions' => collect(['syft' => 'Syft', 'grype' => 'Grype', 'regctl' => 'regctl'])
                ->map(fn (string $name, string $tool) => $name.' '.config("constants.image_scan.images.{$tool}.version"))
                ->implode(', '),
            'builtAt' => is_string($builtAt) ? CarbonImmutable::parse($builtAt) : null,
        ]);
    }
}
