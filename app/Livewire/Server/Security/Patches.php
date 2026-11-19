<?php

namespace App\Livewire\Server\Security;

use App\Actions\Server\ConfigureAutomaticSecurityUpdates;
use App\Jobs\ServerPatchCheckJob;
use App\Livewire\Concerns\ManagesServerPackageUpdates;
use App\Models\Server;
use App\Notifications\Server\ServerPatchCheck;
use App\Traits\ListensToTeamChannel;
use Cron\CronExpression;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class Patches extends Component
{
    use AuthorizesRequests;
    use ListensToTeamChannel;
    use ManagesServerPackageUpdates;
    use WithPagination;

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

    /** Package names are short, so a longer search can match nothing. */
    private const SEARCH_MAX_LENGTH = 100;

    public int $perPage = 10;

    public string $search = '';

    /** @var list<string> Packages of the last check that the user selected to update. */
    public array $selectedPackages = [];

    public function updatedSearch(): void
    {
        $this->search = mb_substr($this->search, 0, self::SEARCH_MAX_LENGTH);
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = max(1, min(100, $this->perPage));
        $this->resetPage();
    }

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
        $this->selectedPackages = array_values(array_intersect($this->selectedPackages, $this->savedPackages()));
    }

    /**
     * @return list<string>
     */
    private function savedPackages(): array
    {
        return collect(data_get($this->server->settings->patch_check_result, 'updates', []))->pluck('package')->all();
    }

    /**
     * Selects all packages that match the search, or clears the selection when they are all selected.
     */
    public function toggleAllPackages(): void
    {
        $search = trim($this->search);
        $matching = array_values(array_filter($this->savedPackages(), fn (string $package) => $search === '' || str($package)->contains($search, ignoreCase: true)));
        $this->selectedPackages = array_diff($matching, $this->selectedPackages) === []
            ? array_values(array_diff($this->selectedPackages, $matching))
            : array_values(array_unique([...$this->selectedPackages, ...$matching]));
    }

    public function updateSelectedPackages()
    {
        try {
            $this->authorize('update', $this->server);
            $packages = array_values(array_intersect($this->savedPackages(), $this->selectedPackages));
            if ($packages === []) {
                $this->dispatch('error', message: 'Select the packages to update.');

                return;
            }
            if ($this->runUpdate(packages: $packages)) {
                $this->selectedPackages = [];
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
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
            if (! in_array($package, $this->savedPackages(), true)) {
                $this->dispatch('error', message: 'This package has no update in the last check.');

                return;
            }
            $this->runUpdate(packages: [$package]);
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
            $this->auditPatchAction('auto_security_updates_updated', [...$auditContext, 'result' => 'failed', 'error' => class_basename($e)], 'error');

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
        $updates = collect(data_get($this->result, 'updates', []))
            ->sortBy(fn ($update) => [data_get($update, 'security') ? 0 : 1, filled(data_get($update, 'risk')) ? 0 : 1, data_get($update, 'package')])
            ->values();
        $search = trim($this->search);
        $matchingUpdates = $search === '' ? $updates : $updates
            ->filter(fn ($update) => str(data_get($update, 'package'))->contains($search, ignoreCase: true))
            ->values();

        $this->perPage = max(1, min(100, $this->perPage));
        $lastPage = max(1, (int) ceil($matchingUpdates->count() / $this->perPage));
        $page = max(1, min((int) $this->getPage(), $lastPage));
        if ($page !== $this->getPage()) {
            $this->setPage($page);
        }

        return view('livewire.server.security.patches', [
            'updates' => $updates->all(),
            'allMatchingSelected' => $matchingUpdates->isNotEmpty() && $matchingUpdates->pluck('package')->diff($this->selectedPackages)->isEmpty(),
            'shownUpdates' => new LengthAwarePaginator(
                $matchingUpdates->forPage($page, $this->perPage)->values(),
                $matchingUpdates->count(),
                $this->perPage,
                $page,
            ),
        ]);
    }
}
