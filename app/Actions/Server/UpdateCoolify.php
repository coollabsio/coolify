<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Notifications\Server\UpgradeSkippedLowDiskSpace;
use App\Services\CoolifyUpgradeDiskSpace;
use App\Services\CoolifyUpgradeStatus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Lorisleiva\Actions\Concerns\AsAction;

class UpdateCoolify
{
    use AsAction;

    public ?Server $server = null;

    public ?string $latestVersion = null;

    public ?string $currentVersion = null;

    public function handle($manual_update = false, bool $skipDiskSpaceCheck = false)
    {
        if (isDev()) {
            Sleep::for(10)->seconds();

            return;
        }
        $settings = instanceSettings();
        $this->server = Server::find(0);
        if (! $this->server) {
            return;
        }

        // Fetch fresh version from CDN instead of using cache
        try {
            $response = Http::retry(3, 1000)->timeout(10)
                ->get(config('constants.coolify.versions_url'));

            if ($response->successful()) {
                $versions = $response->json();
                $this->latestVersion = data_get($versions, 'coolify.v4.version');
            } else {
                // Fallback to cache if CDN unavailable
                $cacheVersion = get_latest_version_of_coolify();

                // Validate cache version against current running version
                if ($cacheVersion && version_compare($cacheVersion, config('constants.coolify.version'), '<')) {
                    Log::error('Failed to fetch fresh version from CDN and cache is corrupted/outdated', [
                        'cached_version' => $cacheVersion,
                        'current_version' => config('constants.coolify.version'),
                    ]);
                    throw new \Exception(
                        'Cannot determine latest version: CDN unavailable and cache version '.
                        "({$cacheVersion}) is older than running version (".config('constants.coolify.version').')'
                    );
                }

                $this->latestVersion = $cacheVersion;
                Log::warning('Failed to fetch fresh version from CDN (unsuccessful response), using validated cache', [
                    'version' => $cacheVersion,
                ]);
            }
        } catch (\Throwable $e) {
            $cacheVersion = get_latest_version_of_coolify();

            // Validate cache version against current running version
            if ($cacheVersion && version_compare($cacheVersion, config('constants.coolify.version'), '<')) {
                Log::error('Failed to fetch fresh version from CDN and cache is corrupted/outdated', [
                    'error' => $e->getMessage(),
                    'cached_version' => $cacheVersion,
                    'current_version' => config('constants.coolify.version'),
                ]);
                throw new \Exception(
                    'Cannot determine latest version: CDN unavailable and cache version '.
                    "({$cacheVersion}) is older than running version (".config('constants.coolify.version').')'
                );
            }

            $this->latestVersion = $cacheVersion;
            Log::warning('Failed to fetch fresh version from CDN, using validated cache', [
                'error' => $e->getMessage(),
                'version' => $cacheVersion,
            ]);
        }

        $this->currentVersion = config('constants.coolify.version');
        if (! $manual_update) {
            if (! $settings->is_auto_update_enabled) {
                return;
            }
            if ($this->latestVersion === $this->currentVersion) {
                return;
            }
            if (version_compare($this->latestVersion, $this->currentVersion, '<')) {
                return;
            }
        }

        // ALWAYS check for downgrades (even for manual updates)
        if (version_compare($this->latestVersion, $this->currentVersion, '<')) {
            Log::error('Downgrade prevented', [
                'target_version' => $this->latestVersion,
                'current_version' => $this->currentVersion,
                'manual_update' => $manual_update,
            ]);
            throw new \Exception(
                "Cannot downgrade from {$this->currentVersion} to {$this->latestVersion}. ".
                'If you need to downgrade, please do so manually via Docker commands.'
            );
        }

        if (! $skipDiskSpaceCheck && ! $this->ensureEnoughDiskSpace($manual_update)) {
            return;
        }

        $this->update($skipDiskSpaceCheck);
        $settings->new_version_available = false;
        $settings->save();
    }

    private function update(bool $skipDiskSpaceCheck)
    {
        $this->ensureNoUpgradeIsRunning();

        $latestHelperImageVersion = getHelperVersion();
        $upgradeScriptUrl = config('constants.coolify.upgrade_script_url');
        $registryUrl = coolifyRegistryUrl();

        $upgradeCommand = 'bash /data/coolify/source/upgrade.sh '.
            escapeshellarg($this->latestVersion).' '.
            escapeshellarg($latestHelperImageVersion).' '.
            escapeshellarg($registryUrl);
        if ($skipDiskSpaceCheck) {
            // Arguments 4 and 5: do not skip the backup, skip the disk space check.
            $upgradeCommand .= " 'false' 'true'";
        }

        remote_process([
            "curl -fsSL {$upgradeScriptUrl} -o /data/coolify/source/upgrade.sh",
            $upgradeCommand,
        ], $this->server);
    }

    /**
     * Automatic updates are skipped and the team is notified. Manual updates fail with an error.
     */
    private function ensureEnoughDiskSpace(bool $manualUpdate): bool
    {
        $availableGb = $this->availableDiskSpaceGb();
        if (! CoolifyUpgradeDiskSpace::isLow($availableGb)) {
            return true;
        }

        Log::warning('Upgrade skipped because of low disk space', [
            'target_version' => $this->latestVersion,
            'available_gb' => $availableGb,
            'required_gb' => CoolifyUpgradeDiskSpace::REQUIRED_GB,
            'manual_update' => $manualUpdate,
        ]);

        if ($manualUpdate) {
            throw new \Exception(
                "Not enough free disk space to upgrade: {$availableGb} GB free, ".CoolifyUpgradeDiskSpace::REQUIRED_GB.' GB required.'
            );
        }

        $this->server->team?->notify(new UpgradeSkippedLowDiskSpace($this->server, $this->latestVersion, $availableGb));

        return false;
    }

    protected function availableDiskSpaceGb(): ?float
    {
        return app(CoolifyUpgradeDiskSpace::class)->availableGb($this->server);
    }

    private function ensureNoUpgradeIsRunning(): void
    {
        $status = $this->readUpgradeStatus();

        if (CoolifyUpgradeStatus::isRunning((string) $status)) {
            Log::warning('Upgrade skipped because another upgrade is running', [
                'target_version' => $this->latestVersion,
                'status' => $status,
            ]);
            throw new \Exception(
                'Another Coolify upgrade is already running. Wait for it to finish. '.
                'If it has stopped, you can upgrade again '.CoolifyUpgradeStatus::RUNNING_LOCK_EXPIRES_AFTER_MINUTES.' minutes after its last status update.'
            );
        }
    }

    protected function readUpgradeStatus(): ?string
    {
        return instant_remote_process(
            ['cat '.CoolifyUpgradeStatus::FILE.' 2>/dev/null || true'],
            $this->server,
            false
        );
    }
}
