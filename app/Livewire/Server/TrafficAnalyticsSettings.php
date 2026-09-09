<?php

namespace App\Livewire\Server;

use App\Actions\Server\ConfigureTrafficAnalytics;
use App\Livewire\Analytics;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

class TrafficAnalyticsSettings extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public bool $isTrafficAnalyticsEnabled;

    #[Validate(['required', 'integer', 'min:1'])]
    public int|string $trafficTopn;

    #[Validate(['required', 'integer', 'min:0'])]
    public int|string $trafficSampleThreshold;

    #[Validate(['required', 'integer', 'min:1'])]
    public int|string $trafficRetention1hDays;

    #[Validate(['required', 'integer', 'min:1'])]
    public int|string $trafficRetention1dDays;

    public bool $isGeoipEnabled;

    #[Validate(['required', 'integer', 'min:1'])]
    public int|string $geoipRefreshDays;

    #[Validate(['nullable', 'string', 'max:255'])]
    public ?string $geoipMaxmindLicenseKey = null;

    public function mount(): void
    {
        $this->authorize('update', $this->server);
        $this->syncData();
    }

    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->validate();
            $this->server->settings->traffic_topn = $this->trafficTopn;
            $this->server->settings->traffic_sample_threshold = $this->trafficSampleThreshold;
            $this->server->settings->traffic_retention_1h_days = $this->trafficRetention1hDays;
            $this->server->settings->traffic_retention_1d_days = $this->trafficRetention1dDays;
            $this->server->settings->is_geoip_enabled = $this->isGeoipEnabled;
            $this->server->settings->geoip_refresh_days = $this->geoipRefreshDays;
            $this->server->settings->geoip_maxmind_license_key = $this->geoipMaxmindLicenseKey;
            $this->server->settings->save();

            return;
        }

        $this->isTrafficAnalyticsEnabled = $this->server->isTrafficAnalyticsEnabled();
        $this->trafficTopn = $this->server->settings->traffic_topn;
        $this->trafficSampleThreshold = $this->server->settings->traffic_sample_threshold;
        $this->trafficRetention1hDays = $this->server->settings->traffic_retention_1h_days;
        $this->trafficRetention1dDays = $this->server->settings->traffic_retention_1d_days;
        $this->isGeoipEnabled = (bool) $this->server->settings->is_geoip_enabled;
        $this->geoipRefreshDays = $this->server->settings->geoip_refresh_days;
        $this->geoipMaxmindLicenseKey = $this->server->settings->geoip_maxmind_license_key;
    }

    public function toggleTrafficAnalytics(): void
    {
        try {
            $this->authorize('update', $this->server);
            if ($this->server->isSwarm() || $this->server->isBuildServer()) {
                $this->dispatch('error', 'Traffic analytics is not supported on Swarm/Build servers.');

                return;
            }

            $enable = ! $this->server->isTrafficAnalyticsEnabled();
            ConfigureTrafficAnalytics::run($this->server, $enable);
            $this->server->refresh();
            $this->isTrafficAnalyticsEnabled = $this->server->isTrafficAnalyticsEnabled();
            $this->dispatch('trafficAnalyticsStateChanged')->to(Analytics::class);
            $this->dispatch('success', $enable
                ? 'Traffic analytics enabled. Restarting proxy and Sentinel.'
                : 'Traffic analytics disabled. Restarting proxy and Sentinel.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function saveTrafficAnalyticsSettings(): void
    {
        try {
            $this->authorize('update', $this->server);
            $this->syncData(true);
            $this->dispatch('success', 'Traffic analytics settings updated. Restarting Sentinel.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render(): View
    {
        return view('livewire.server.traffic-analytics-settings');
    }
}
