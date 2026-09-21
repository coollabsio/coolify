<?php

namespace App\Livewire;

use App\Actions\Server\UpdateCoolify;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Services\CoolifyUpgradeStatus;
use App\Services\CoolifyUpdateTargetResolver;
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
        $rollingChannel = config('constants.coolify.latest_image') === 'next';
        if ($rollingChannel && ! CoolifyUpdateTargetResolver::isRollingBuildVersion($this->latestVersion)) {
            $this->latestVersion = '';
        }

        $hasNewerVersion = $rollingChannel
            ? $this->latestVersion !== '' && $this->latestVersion !== $this->currentVersion
            : version_compare($this->latestVersion, $this->currentVersion, '>');
        $newVersionAvailable = (bool) data_get($settings, 'new_version_available', false);

        if ($settings && $newVersionAvailable && ! $hasNewerVersion) {
            $settings->update(['new_version_available' => false]);
            $newVersionAvailable = false;
        }

        $this->isUpgradeAvailable = $hasNewerVersion && $newVersionAvailable;
    }

    public function upgrade()
    {
        try {
            if (! isInstanceAdmin()) {
                abort(403);
            }
            if ($this->updateInProgress) {
                return;
            }
            $this->updateInProgress = true;
            dispatch(function () {
                try {
                    UpdateCoolify::run(manual_update: true);
                } catch (\Throwable $e) {
                    report($e);
                }
            })->afterResponse();
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

        $statusFile = '/data/coolify/source/.upgrade-status';

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

        $targetVersion = $this->latestVersion;
        if ($targetVersion === '' && config('constants.coolify.latest_image') !== 'next') {
            $targetVersion = get_latest_version_of_coolify();
        }

        return CoolifyUpgradeStatus::fromFile(
            content: $content,
            runningVersion: $this->currentVersion !== '' ? $this->currentVersion : (string) config('constants.coolify.version'),
            targetVersion: $targetVersion,
        );
    }
}
