<?php

namespace App\Livewire\ImageScan;

use App\Jobs\PrepareImageScannerJob;
use App\Models\InstanceSettings;
use App\Models\Server;
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
            $this->ensureManagedHere();
            if (! InstanceSettings::get()->is_image_scanning_allowed) {
                $this->dispatch('error', message: 'Image scanning is turned off for this Coolify instance.');

                return;
            }

            $settings = $this->server->settings;
            $settings->is_image_scan_enabled = true;
            $settings->save();
            $this->auditImageScanning('image_scanning_enabled');
            $this->startPreparing();
            $this->dispatch('success', 'Image scanning turned on. Coolify is getting the scanner.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function prepareScanner()
    {
        $this->authorize('update', $this->server);

        try {
            $this->ensureManagedHere();
            if (! InstanceSettings::get()->is_image_scanning_allowed || ! $this->isEnabled()) {
                $this->dispatch('error', message: 'Turn on image scanning first.');

                return;
            }

            $this->startPreparing();
            $this->dispatch('success', 'Coolify is getting the scanner.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function disable()
    {
        $this->authorize('update', $this->server);

        try {
            $settings = $this->server->settings;
            $settings->is_image_scan_enabled = false;
            $settings->save();
            PrepareImageScannerJob::forget($this->server);
            $this->auditImageScanning('image_scanning_disabled');
            $this->dispatch('success', 'Image scanning turned off.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function deleteScannerData()
    {
        $this->authorize('update', $this->server);

        try {
            $this->ensureManagedHere();
            $commands = new ScannerCommands($this->server, Str::lower(Str::random(24)));
            instant_remote_process($commands->deleteScannerData(), $this->server, throwError: true, timeout: 120);
            PrepareImageScannerJob::forget($this->server);
            $this->auditImageScanning('image_scanner_data_deleted');
            $this->dispatch('success', 'Scanner data deleted.');
        } catch (\Throwable $e) {
            return handleError($e, $this, 'Could not delete the scanner data.');
        }
    }

    private function startPreparing(): void
    {
        Cache::put(PrepareImageScannerJob::stateCacheKey($this->server), PrepareImageScannerJob::STATE_PULLING, now()->addHour());
        PrepareImageScannerJob::dispatch($this->server);
    }

    private function isEnabled(): bool
    {
        return (bool) $this->server->settings->refresh()->is_image_scan_enabled;
    }

    private function ensureManagedHere(): void
    {
        if ($this->server->isTransferredAway()) {
            throw new \RuntimeException('This server is managed by another Coolify instance.');
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
            'allowed' => (bool) InstanceSettings::get()->is_image_scanning_allowed,
            'enabled' => (bool) $this->server->settings->is_image_scan_enabled,
            'prepareState' => is_string($state) ? $state : null,
            'failedStep' => is_string($failedStep) ? $failedStep : null,
            'diskUsed' => is_int($diskUsed) ? formatBytes($diskUsed, 1) : null,
            'toolVersions' => collect(['syft' => 'Syft', 'grype' => 'Grype', 'regctl' => 'regctl'])
                ->map(fn (string $name, string $tool) => $name.' '.config("constants.image_scan.images.{$tool}.version"))
                ->implode(', '),
            'builtAt' => is_string($builtAt) ? CarbonImmutable::parse($builtAt) : null,
        ]);
    }
}
