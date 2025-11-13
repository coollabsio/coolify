<?php

namespace App\Livewire\Server;

use App\Actions\Proxy\GetProxyConfiguration;
use App\Actions\Proxy\SaveProxyConfiguration;
use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\File;
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

    public function getLatestTraefikVersionProperty(): ?string
    {
        try {
            $versionsPath = base_path('versions.json');
            if (! File::exists($versionsPath)) {
                return null;
            }

            $versions = json_decode(File::get($versionsPath), true);
            $traefikVersions = data_get($versions, 'traefik');

            if (! $traefikVersions) {
                return null;
            }

            // Handle new structure (array of branches)
            if (is_array($traefikVersions)) {
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
            }

            // Handle old structure (simple string) for backward compatibility
            return str_starts_with($traefikVersions, 'v') ? $traefikVersions : "v{$traefikVersions}";
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

    public function getNewerTraefikBranchAvailableProperty(): ?string
    {
        try {
            if ($this->server->proxyType() !== ProxyTypes::TRAEFIK->value) {
                return null;
            }

            $currentVersion = $this->server->detected_traefik_version;
            if (! $currentVersion || $currentVersion === 'latest') {
                return null;
            }

            $versionsPath = base_path('versions.json');
            if (! File::exists($versionsPath)) {
                return null;
            }

            $versions = json_decode(File::get($versionsPath), true);
            $traefikVersions = data_get($versions, 'traefik');

            if (! is_array($traefikVersions)) {
                return null;
            }

            // Extract current branch (e.g., "3.5" from "3.5.6")
            $current = ltrim($currentVersion, 'v');
            if (! preg_match('/^(\d+\.\d+)/', $current, $matches)) {
                return null;
            }

            $currentBranch = $matches[1];

            // Find the newest branch that's greater than current
            $newestVersion = null;
            foreach ($traefikVersions as $branch => $version) {
                $branchNum = ltrim($branch, 'v');
                if (version_compare($branchNum, $currentBranch, '>')) {
                    $cleanVersion = ltrim($version, 'v');
                    if (! $newestVersion || version_compare($cleanVersion, $newestVersion, '>')) {
                        $newestVersion = $cleanVersion;
                    }
                }
            }

            return $newestVersion ? "v{$newestVersion}" : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
