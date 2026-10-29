<?php

namespace App\Livewire\Server\Security;

use App\Actions\Server\ConfigureAutomaticSecurityUpdates;
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

    #[Locked]
    public bool $autoSecurityUpdates = false;

    public string $autoSecurityUpdatesTime = '03:00';

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
        $this->syncSettings();
    }

    private function syncSettings(): void
    {
        $this->patchCheckFrequency = (string) $this->server->settings->patch_check_frequency;
        $this->autoSecurityUpdates = (bool) $this->server->settings->is_auto_security_updates_enabled;
        $this->autoSecurityUpdatesTime = (string) ($this->server->settings->auto_security_updates_time ?? '03:00');
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
            try {
                RebootServer::run($this->server);
            } catch (\Throwable $e) {
                $this->auditPatchAction('reboot_requested', ['result' => 'failed', 'error' => $e->getMessage()], 'error');

                throw $e;
            }
            $this->auditPatchAction('reboot_requested', ['result' => 'started']);
            $this->dispatch('success', 'Reboot started. The server is back in a few minutes, and Coolify then checks it again.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->server);
            $settings = $this->server->settings;

            $frequency = trim($this->patchCheckFrequency);
            if ($frequency !== '' && ! self::isValidPatchCheckFrequency($frequency)) {
                throw new \Exception('Invalid check frequency. Use a cron expression or a preset (daily, weekly, monthly) that runs at most once per hour.');
            }
            $time = trim($this->autoSecurityUpdatesTime);
            if (preg_match(ConfigureAutomaticSecurityUpdates::TIME_PATTERN, $time) !== 1) {
                throw new \Exception('Invalid time. Use the 24-hour format HH:MM, for example 03:00.');
            }

            if ($settings->is_auto_security_updates_enabled && $time !== $settings->auto_security_updates_time) {
                $this->applyAutomaticSecurityUpdates(true, $time);
            }

            $settings->patch_check_frequency = $frequency === '' ? null : $frequency;
            $settings->auto_security_updates_time = $time;
            $changedFields = auditChangedFields($settings);
            $settings->save();
            if ($changedFields !== []) {
                $this->auditPatchAction('patch_settings_updated', ['changed_fields' => $changedFields]);
            }
            $this->syncSettings();
            $this->dispatch('success', 'Patch settings saved.');
        } catch (\Throwable $e) {
            $this->server->settings->refresh();
            $this->syncSettings();

            return handleError($e, $this);
        }
    }

    /**
     * Turns automatic security updates on or off after the user confirmed it.
     */
    public function toggleAutomaticSecurityUpdates()
    {
        try {
            $this->authorize('update', $this->server);
            $settings = $this->server->settings;
            $enabled = ! $settings->is_auto_security_updates_enabled;
            $time = trim($this->autoSecurityUpdatesTime);
            if (preg_match(ConfigureAutomaticSecurityUpdates::TIME_PATTERN, $time) !== 1) {
                throw new \Exception('Invalid time. Use the 24-hour format HH:MM, for example 03:00.');
            }

            $this->applyAutomaticSecurityUpdates($enabled, $time);
            $settings->is_auto_security_updates_enabled = $enabled;
            $settings->auto_security_updates_time = $time;
            $settings->save();
            $this->syncSettings();
            $this->dispatch('success', $enabled ? 'Automatic security updates turned on.' : 'Automatic security updates turned off.');
        } catch (\Throwable $e) {
            $this->server->settings->refresh();
            $this->syncSettings();

            return handleError($e, $this);
        }
    }

    private function applyAutomaticSecurityUpdates(bool $enabled, string $time): void
    {
        $this->ensureManagedHere();
        $packageManager = data_get($this->server->settings->patch_check_result, 'package_manager');
        if (! ConfigureAutomaticSecurityUpdates::supportFor($packageManager)) {
            throw new \Exception('Automatic security updates are supported only on Debian, Ubuntu, and RHEL-family servers. Run "Check for updates" first, so Coolify knows the package manager.');
        }

        $auditContext = ['enabled' => $enabled, 'time' => $time, 'package_manager' => $packageManager];
        try {
            ConfigureAutomaticSecurityUpdates::run($this->server, $packageManager, $enabled, $time);
        } catch (\Throwable $e) {
            $this->auditPatchAction('auto_security_updates_updated', [...$auditContext, 'result' => 'failed', 'error' => $e->getMessage()], 'error');

            throw $e;
        }
        $this->auditPatchAction('auto_security_updates_updated', [...$auditContext, 'result' => 'success']);
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
     * @param  array<string, mixed>  $context
     */
    private function auditPatchAction(string $action, array $context, string $level = 'info'): void
    {
        auditLog("ui.server.{$action}", [
            'team_id' => $this->server->team_id,
            'server_uuid' => $this->server->uuid,
            'server_name' => $this->server->name,
            ...$context,
        ], $level);
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
        $this->auditPatchAction('packages_update_requested', [
            'packages' => $all ? 'all' : [$package],
            'package_manager' => $packageManager,
            'activity_id' => $activity->id,
        ]);
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
