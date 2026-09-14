<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Services\CoolifyVersionSelector;
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

    public function handle(bool $manual_update = false): void
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

        $versions = null;
        $cachedVersions = $settings->new_version_available ? get_versions_data() : null;
        try {
            $response = Http::retry(3, 1000)->timeout(10)
                ->connectTimeout(10)
                ->get(config('constants.coolify.versions_url'));

            if ($response->successful()) {
                $versions = $response->json();
            } else {
                $versions = get_versions_data();
                $cachedVersions = $versions;
                Log::warning('Failed to fetch fresh version from CDN (unsuccessful response), using validated cache', [
                    'versions_available' => is_array($versions),
                ]);
            }
        } catch (\Throwable $e) {
            $versions = get_versions_data();
            $cachedVersions = $versions;
            Log::warning('Failed to fetch fresh version from CDN, using validated cache', [
                'error' => $e->getMessage(),
                'versions_available' => is_array($versions),
            ]);
        }

        $this->currentVersion = config('constants.coolify.version');
        $versions = is_array($versions) ? $versions : [];
        $versions = CoolifyVersionSelector::reconcileMetadata($versions, $cachedVersions, $this->currentVersion);
        $this->latestVersion = $manual_update
            ? CoolifyVersionSelector::forManual($versions, $this->currentVersion, $settings->update_channel ?: 'stable')
            : CoolifyVersionSelector::forAutomatic($versions, $this->currentVersion, $settings->auto_update_scope ?: 'minor');

        if (! $manual_update) {
            if (! $settings->is_auto_update_enabled) {
                return;
            }
        }

        if (version_compare($this->latestVersion, $this->currentVersion, '<=')) {
            return;
        }

        $this->update();
        $settings->new_version_available = false;
        $settings->save();
    }

    private function update(): void
    {
        $latestHelperImageVersion = getHelperVersion();
        $upgradeScriptUrl = CoolifyVersionSelector::isReleaseCandidate($this->latestVersion)
            ? config('constants.coolify.rc_upgrade_script_url')
            : config('constants.coolify.upgrade_script_url');
        $registryUrl = coolifyRegistryUrl();

        remote_process([
            "curl -fsSL {$upgradeScriptUrl} -o /data/coolify/source/upgrade.sh",
            'bash /data/coolify/source/upgrade.sh '.
                escapeshellarg($this->latestVersion).' '.
                escapeshellarg($latestHelperImageVersion).' '.
                escapeshellarg($registryUrl),
        ], $this->server);
    }
}
