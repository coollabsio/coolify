<?php

namespace App\Livewire\Server;

use App\Actions\Proxy\GetProxyConfiguration;
use App\Actions\Proxy\SaveProxyConfiguration;
use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Proxy extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public ?string $selectedProxy = null;

    public $proxySettings = null;

    public bool $redirectEnabled = true;

    public ?string $redirectUrl = null;

    public bool $generateExactLabels = false;

    /**
     * Cache the versions.json file data in memory for this component instance.
     * This avoids multiple file reads during a single request/render cycle.
     */
    protected ?array $cachedVersionsFile = null;

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            'saveConfiguration' => 'submit',
            "echo-private:team.{$teamId},ProxyStatusChangedUI" => '$refresh',
        ];
    }

    protected $rules = [
        'generateExactLabels' => 'required|boolean',
    ];

    public function mount()
    {
        $this->selectedProxy = $this->server->proxyType();
        $this->redirectEnabled = data_get($this->server, 'proxy.redirect_enabled', true);
        $this->redirectUrl = data_get($this->server, 'proxy.redirect_url');
        $this->syncData(false);
    }

    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->server->settings->generate_exact_labels = $this->generateExactLabels;
        } else {
            $this->generateExactLabels = $this->server->settings->generate_exact_labels ?? false;
        }
    }

    /**
     * Get Traefik versions from cached data with in-memory optimization.
     * Returns array like: ['v3.5' => '3.5.6', 'v3.6' => '3.6.2']
     *
     * This method adds an in-memory cache layer on top of the global
     * get_traefik_versions() helper to avoid multiple calls during
     * a single component lifecycle/render.
     */
    protected function getTraefikVersions(): ?array
    {
        // In-memory cache for this component instance (per-request)
        if ($this->cachedVersionsFile !== null) {
            return data_get($this->cachedVersionsFile, 'traefik');
        }

        // Load from global cached helper (Redis + filesystem)
        $versionsData = get_versions_data();
        $this->cachedVersionsFile = $versionsData;

        if (! $versionsData) {
            return null;
        }

        $traefikVersions = data_get($versionsData, 'traefik');

        return is_array($traefikVersions) ? $traefikVersions : null;
    }

    public function getConfigurationFilePathProperty()
    {
        return $this->server->proxyPath().'docker-compose.yml';
    }

    public function changeProxy()
    {
        $this->authorize('update', $this->server);
        $this->server->proxy = null;
        $this->server->save();

        $this->dispatch('reloadWindow');
    }

    public function selectProxy($proxy_type)
    {
        try {
            $this->authorize('update', $this->server);
            $this->server->changeProxy($proxy_type, async: false);
            $this->selectedProxy = $this->server->proxy->type;

            $this->dispatch('reloadWindow');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->server);
            $this->validate();
            $this->syncData(true);
            $this->server->settings->save();
            $this->dispatch('success', 'Settings saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSaveRedirect()
    {
        try {
            $this->authorize('update', $this->server);
            $this->server->proxy->redirect_enabled = $this->redirectEnabled;
            $this->server->save();
            $this->server->setupDefaultRedirect();
            $this->dispatch('success', 'Proxy configuration saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->server);
            SaveProxyConfiguration::run($this->server, $this->proxySettings);
            $this->server->proxy->redirect_url = $this->redirectUrl;
            $this->server->save();
            $this->server->setupDefaultRedirect();
            $this->dispatch('success', 'Proxy configuration saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function resetProxyConfiguration()
    {
        try {
            $this->authorize('update', $this->server);
            // Explicitly regenerate default configuration
            $this->proxySettings = GetProxyConfiguration::run($this->server, forceRegenerate: true);
            SaveProxyConfiguration::run($this->server, $this->proxySettings);
            $this->server->save();
            $this->dispatch('success', 'Proxy configuration reset to default.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function loadProxyConfiguration()
    {
        try {
            $this->proxySettings = GetProxyConfiguration::run($this->server);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Get the latest Traefik version for this server's current branch.
     *
     * This compares the server's detected version against available versions
     * in versions.json to determine the latest patch for the current branch,
     * or the newest available version if no current version is detected.
     */
    public function getLatestTraefikVersionProperty(): ?string
    {
        try {
            $traefikVersions = $this->getTraefikVersions();

            if (! $traefikVersions) {
                return null;
            }

            // Get this server's current version
            $currentVersion = $this->server->detected_traefik_version;

            // If we have a current version, try to find matching branch
            if ($currentVersion && $currentVersion !== 'latest') {
                $current = ltrim($currentVersion, 'v');
                if (preg_match('/^(\d+\.\d+)/', $current, $matches)) {
                    $branch = "v{$matches[1]}";
                    if (isset($traefikVersions[$branch])) {
                        $version = $traefikVersions[$branch];

                        return str_starts_with($version, 'v') ? $version : "v{$version}";
                    }
                }
            }

            // Return the newest available version
            $newestVersion = collect($traefikVersions)
                ->map(fn ($v) => ltrim($v, 'v'))
                ->sortBy(fn ($v) => $v, SORT_NATURAL)
                ->last();

            return $newestVersion ? "v{$newestVersion}" : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getIsTraefikOutdatedProperty(): bool
    {
        if ($this->server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            return false;
        }

        $currentVersion = $this->server->detected_traefik_version;
        if (! $currentVersion || $currentVersion === 'latest') {
            return false;
        }

        $latestVersion = $this->latestTraefikVersion;
        if (! $latestVersion) {
            return false;
        }

        // Compare versions (strip 'v' prefix)
        $current = ltrim($currentVersion, 'v');
        $latest = ltrim($latestVersion, 'v');

        return version_compare($current, $latest, '<');
    }

    /**
     * Check if a newer Traefik branch (minor version) is available for this server.
     * Returns the branch identifier (e.g., "v3.6") if a newer branch exists.
     */
    public function getNewerTraefikBranchAvailableProperty(): ?string
    {
        try {
            if ($this->server->proxyType() !== ProxyTypes::TRAEFIK->value) {
                return null;
            }

            // Get this server's current version
            $currentVersion = $this->server->detected_traefik_version;
            if (! $currentVersion || $currentVersion === 'latest') {
                return null;
            }

            // Check if we have outdated info stored for this server (faster than computing)
            $outdatedInfo = $this->server->traefik_outdated_info;
            if ($outdatedInfo && isset($outdatedInfo['type']) && $outdatedInfo['type'] === 'minor_upgrade') {
                // Use the upgrade_target field if available (e.g., "v3.6")
                if (isset($outdatedInfo['upgrade_target'])) {
                    return str_starts_with($outdatedInfo['upgrade_target'], 'v')
                        ? $outdatedInfo['upgrade_target']
                        : "v{$outdatedInfo['upgrade_target']}";
                }
            }

            // Fallback: compute from cached versions data
            $traefikVersions = $this->getTraefikVersions();

            if (! $traefikVersions) {
                return null;
            }

            // Extract current branch (e.g., "3.5" from "3.5.6")
            $current = ltrim($currentVersion, 'v');
            if (! preg_match('/^(\d+\.\d+)/', $current, $matches)) {
                return null;
            }

            $currentBranch = $matches[1];

            // Find the newest branch that's greater than current
            $newestBranch = null;
            foreach ($traefikVersions as $branch => $version) {
                $branchNum = ltrim($branch, 'v');
                if (version_compare($branchNum, $currentBranch, '>')) {
                    if (! $newestBranch || version_compare($branchNum, $newestBranch, '>')) {
                        $newestBranch = $branchNum;
                    }
                }
            }

            return $newestBranch ? "v{$newestBranch}" : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
