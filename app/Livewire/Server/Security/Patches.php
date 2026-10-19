<?php

namespace App\Livewire\Server\Security;

use App\Actions\Server\RebootServer;
use App\Actions\Server\UpdatePackage;
use App\Jobs\ServerPatchCheckJob;
use App\Models\Server;
use App\Notifications\Server\ServerPatchCheck;
use App\Traits\ListensToTeamChannel;
use Cron\CronExpression;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Patches extends Component
{
    use AuthorizesRequests;
    use ListensToTeamChannel;

    public Server $server;

    /**
     * The saved result of the last check (see ServerPatchCheckJob).
     */
    #[Locked]
    public ?array $result = null;

    #[Locked]
    public ?string $error = null;

    #[Locked]
    public bool $checking = false;

    public string $patchCheckFrequency = '';

    public function getListeners()
    {
        return $this->teamChannelListeners([
            'ServerPatchCheckFinished' => 'patchCheckFinished',
        ]);
    }

    public function mount(string $server_uuid)
    {
        $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('viewSecurity', $this->server);
        $this->loadResult();
        $this->patchCheckFrequency = (string) $this->server->settings->patch_check_frequency;
    }

    public function loadResult(): void
    {
        $this->server->settings->refresh();
        $this->result = $this->server->settings->patch_check_result;
        $this->error = $this->server->settings->patch_check_error;
        $this->checking = Cache::has(ServerPatchCheckJob::runningCacheKey($this->server));
    }

    public function patchCheckFinished(array $event = []): void
    {
        $serverUuid = data_get($event, 'serverUuid');
        if ($serverUuid !== null && $serverUuid !== $this->server->uuid) {
            return;
        }
        $this->loadResult();
    }

    public function checkForUpdates()
    {
        try {
            $this->authorize('update', $this->server);
            $this->ensureManagedHere();
            ServerPatchCheckJob::dispatchManual($this->server);
            $this->checking = true;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function updateAllPackages()
    {
        try {
            $this->authorize('update', $this->server);
            $this->runUpdate(all: true);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function updatePackage(string $package)
    {
        try {
            $this->authorize('update', $this->server);
            $savedPackages = collect(data_get($this->server->settings->patch_check_result, 'updates', []))->pluck('package');
            if (! $savedPackages->contains($package)) {
                $this->dispatch('error', message: 'This package has no update in the last check.');

                return;
            }
            $this->runUpdate(package: $package);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function rebootServer()
    {
        try {
            $this->authorize('update', $this->server);
            RebootServer::run($this->server);
            $this->dispatch('success', 'Reboot started. The server is back in a few minutes, and Coolify then checks it again.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function savePatchCheckFrequency()
    {
        try {
            $this->authorize('update', $this->server);
            $frequency = trim($this->patchCheckFrequency);
            if ($frequency !== '' && ! self::isValidPatchCheckFrequency($frequency)) {
                $this->patchCheckFrequency = (string) $this->server->settings->patch_check_frequency;
                throw new \Exception('Invalid check frequency. Use a cron expression or a preset (daily, weekly, monthly) that runs at most once per hour.');
            }
            $this->server->settings->patch_check_frequency = $frequency === '' ? null : $frequency;
            $this->server->settings->save();
            $this->patchCheckFrequency = $frequency;
            $this->dispatch('success', 'Check frequency saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * A valid cron expression or preset that runs at most once per hour, because every check runs the package manager.
     */
    public static function isValidPatchCheckFrequency(string $frequency): bool
    {
        if (! validate_cron_expression($frequency)) {
            return false;
        }
        $runs = (new CronExpression(VALID_CRON_STRINGS[$frequency] ?? $frequency))->getMultipleRunDates(5);
        for ($i = 1; $i < count($runs); $i++) {
            if ($runs[$i]->getTimestamp() - $runs[$i - 1]->getTimestamp() < 3600) {
                return false;
            }
        }

        return true;
    }

    /**
     * A transferred server is an archive: another instance manages the host.
     */
    private function ensureManagedHere(): void
    {
        if ($this->server->isTransferredAway()) {
            throw new \RuntimeException('This server is managed by another Coolify instance.');
        }
    }

    private function runUpdate(bool $all = false, ?string $package = null): void
    {
        $this->ensureManagedHere();
        $savedResult = $this->server->settings->patch_check_result;
        $packageManager = data_get($savedResult, 'package_manager');
        $osId = data_get($savedResult, 'osId');
        if (! $packageManager || ! $osId) {
            $this->dispatch('error', message: 'Run "Check for updates" first.');

            return;
        }

        $activity = UpdatePackage::run(server: $this->server, osId: $osId, package: $package, packageManager: $packageManager, all: $all);
        if (is_array($activity)) {
            $this->dispatch('error', message: $activity['error'] ?? 'Something went wrong.');

            return;
        }
        $this->dispatch('startupdate');
        $this->dispatch('activityMonitor', $activity->id);
    }

    public function sendTestEmail()
    {
        if (! isDev()) {
            $this->dispatch('error', message: 'Test email functionality is only available in development mode.');

            return;
        }

        try {
            $this->server->team->notify(new ServerPatchCheck($this->server, $this->createTestPatchData()));

            $this->dispatch('success', 'Test email sent successfully! Check your email inbox.');
        } catch (\Exception $e) {
            $this->dispatch('error', message: 'Failed to send test email: '.$e->getMessage());
        }
    }

    private function createTestPatchData(): array
    {
        if (count(data_get($this->result, 'updates', [])) > 0) {
            return $this->result;
        }

        return [
            'total_updates' => 3,
            'updates' => [
                ['package' => 'docker-ce', 'current_version' => '24.0.7-1', 'new_version' => '25.0.1-1', 'architecture' => 'amd64', 'repository' => 'stable'],
                ['package' => 'openssh-server', 'current_version' => '8.9p1-3', 'new_version' => '9.0p1-1', 'architecture' => 'amd64', 'repository' => 'noble-security'],
                ['package' => 'curl', 'current_version' => '7.81.0-1', 'new_version' => '7.85.0-1', 'architecture' => 'amd64', 'repository' => 'noble-updates'],
            ],
            'osId' => 'ubuntu',
            'package_manager' => 'apt',
        ];
    }

    public function render()
    {
        return view('livewire.server.security.patches');
    }
}
