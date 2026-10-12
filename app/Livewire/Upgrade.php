<?php

namespace App\Livewire;

use App\Actions\Server\UpdateCoolify;
use App\Jobs\DockerCleanupJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Services\CoolifyUpgradeDiskSpace;
use App\Services\CoolifyUpgradeStatus;
use Livewire\Component;

class Upgrade extends Component
{
    public bool $updateInProgress = false;

    public bool $isUpgradeAvailable = false;

    public string $latestVersion = '';

    public string $currentVersion = '';

    public bool $devMode = false;

    public bool $fullButton = false;

    protected $listeners = ['updateAvailable' => 'checkUpdate'];

    public function mount()
    {
        $this->refreshUpgradeState();
    }

    public function checkUpdate()
    {
        try {
            $this->refreshUpgradeState();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    protected function refreshUpgradeState(): void
    {
        $this->currentVersion = config('constants.coolify.version');
        $this->latestVersion = get_latest_version_of_coolify();
        $this->devMode = isDev();

        if ($this->devMode) {
            $this->isUpgradeAvailable = true;

            return;
        }

        $settings = InstanceSettings::find(0);
        $hasNewerVersion = version_compare($this->latestVersion, $this->currentVersion, '>');
        $newVersionAvailable = (bool) data_get($settings, 'new_version_available', false);

        if ($settings && $newVersionAvailable && ! $hasNewerVersion) {
            $settings->update(['new_version_available' => false]);
            $newVersionAvailable = false;
        }

        $this->isUpgradeAvailable = $hasNewerVersion && $newVersionAvailable;
    }

    /**
     * Start the upgrade. Without $skipDiskSpaceCheck, the upgrade does not start when the disk is almost full.
     *
     * @return array{status: 'started'|'low_disk_space', available_gb?: float|null, required_gb?: int}|null
     */
    public function upgrade(bool $skipDiskSpaceCheck = false)
    {
        try {
            if (! isInstanceAdmin()) {
                abort(403);
            }
            if ($this->updateInProgress) {
                return null;
            }
            if (! $skipDiskSpaceCheck) {
                $diskSpace = $this->checkDiskSpace();
                if ($diskSpace['status'] === 'low_disk_space') {
                    return $diskSpace;
                }
            }
            $this->updateInProgress = true;
            dispatch(function () use ($skipDiskSpaceCheck) {
                try {
                    UpdateCoolify::run(manual_update: true, skipDiskSpaceCheck: $skipDiskSpaceCheck);
                } catch (\Throwable $e) {
                    report($e);
                }
            })->afterResponse();

            auditLog('ui.instance.upgrade_started', [
                'team_id' => null,
                'resource' => 'instance',
                'from_version' => config('constants.coolify.version'),
                'to_version' => get_latest_version_of_coolify(),
                'skip_disk_space_check' => $skipDiskSpaceCheck,
            ]);

            return ['status' => 'started'];
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * @return array{status: 'low_disk_space'|'ok', available_gb: float|null, required_gb: int}
     */
    public function checkDiskSpace(): array
    {
        if (! isInstanceAdmin()) {
            abort(403);
        }

        $server = Server::find(0);
        $availableGb = $server ? app(CoolifyUpgradeDiskSpace::class)->availableGb($server) : null;

        return [
            'status' => CoolifyUpgradeDiskSpace::isLow($availableGb) ? 'low_disk_space' : 'ok',
            'available_gb' => $availableGb,
            'required_gb' => CoolifyUpgradeDiskSpace::REQUIRED_GB,
        ];
    }

    public function runDockerCleanup()
    {
        try {
            if (! isInstanceAdmin()) {
                abort(403);
            }
            $server = Server::findOrFail(0);
            DockerCleanupJob::dispatch($server, true);
            auditLog('ui.server.docker_cleanup_started', [
                'team_id' => $server->team_id,
                'server_uuid' => $server->uuid,
                'server_name' => $server->name,
                'delete_unused_volumes' => false,
                'delete_unused_networks' => false,
            ]);
            $this->dispatch('success', 'Docker cleanup started. When it is done, click "Check again".');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function getUpgradeStatus(): array
    {
        // Only root team members can view upgrade status
        if (auth()->user()?->currentTeam()?->id !== 0) {
            return ['status' => 'none'];
        }

        $server = Server::find(0);
        if (! $server) {
            return ['status' => 'none'];
        }

        $statusFile = CoolifyUpgradeStatus::FILE;

        try {
            $content = instant_remote_process(
                ["cat {$statusFile} 2>/dev/null || echo ''"],
                $server,
                false
            );
            $content = trim($content ?? '');
        } catch (\Throwable $e) {
            return ['status' => 'none'];
        }

        return CoolifyUpgradeStatus::fromFile(
            content: $content,
            runningVersion: $this->currentVersion !== '' ? $this->currentVersion : (string) config('constants.coolify.version'),
            targetVersion: $this->latestVersion !== '' ? $this->latestVersion : get_latest_version_of_coolify(),
        );
    }
}
