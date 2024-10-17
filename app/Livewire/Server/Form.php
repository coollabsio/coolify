<?php

namespace App\Livewire\Server;

use App\Actions\Server\StartSentinel;
use App\Actions\Server\StopSentinel;
use App\Jobs\DockerCleanupJob;
use App\Jobs\PullSentinelImageJob;
use App\Models\Server;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

class Form extends Component
{
    public Server $server;

    public bool $isValidConnection = false;

    public bool $isValidDocker = false;

    public ?string $wildcard_domain = null;

    public bool $dockerInstallationStarted = false;

    public bool $revalidate = false;

    public $timezones;

    public $delete_unused_volumes = false;

    public $delete_unused_networks = false;

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},CloudflareTunnelConfigured" => 'cloudflareTunnelConfigured',
            'refreshServerShow' => 'serverInstalled',
            'revalidate' => '$refresh',
        ];
    }

    protected $rules = [
        'server.name' => 'required',
        'server.description' => 'nullable',
        'server.ip' => 'required',
        'server.user' => 'required',
        'server.port' => 'required',
        'server.settings.is_cloudflare_tunnel' => 'required|boolean',
        'server.settings.is_reachable' => 'required',
        'server.settings.is_swarm_manager' => 'required|boolean',
        'server.settings.is_swarm_worker' => 'required|boolean',
        'server.settings.is_build_server' => 'required|boolean',
        'server.settings.concurrent_builds' => 'required|integer|min:1',
        'server.settings.dynamic_timeout' => 'required|integer|min:1',
        'server.settings.is_metrics_enabled' => 'required|boolean',
        'server.settings.sentinel_token' => 'required',
        'server.settings.sentinel_metrics_refresh_rate_seconds' => 'required|integer|min:1',
        'server.settings.sentinel_metrics_history_days' => 'required|integer|min:1',
        'server.settings.sentinel_push_interval_seconds' => 'required|integer|min:10',
        'wildcard_domain' => 'nullable|url',
        'server.settings.sentinel_custom_url' => 'nullable|url',
        'server.settings.is_sentinel_enabled' => 'required|boolean',
        'server.settings.server_timezone' => 'required|string|timezone',
        'server.settings.force_docker_cleanup' => 'required|boolean',
        'server.settings.docker_cleanup_frequency' => 'required_if:server.settings.force_docker_cleanup,true|string',
        'server.settings.docker_cleanup_threshold' => 'required_if:server.settings.force_docker_cleanup,false|integer|min:1|max:100',
        'server.settings.delete_unused_volumes' => 'boolean',
        'server.settings.delete_unused_networks' => 'boolean',
    ];

    protected $validationAttributes = [
        'server.name' => 'Name',
        'server.description' => 'Description',
        'server.ip' => 'IP address/Domain',
        'server.user' => 'User',
        'server.port' => 'Port',
        'server.settings.is_cloudflare_tunnel' => 'Cloudflare Tunnel',
        'server.settings.is_reachable' => 'Is reachable',
        'server.settings.is_swarm_manager' => 'Swarm Manager',
        'server.settings.is_swarm_worker' => 'Swarm Worker',
        'server.settings.is_build_server' => 'Build Server',
        'server.settings.concurrent_builds' => 'Concurrent Builds',
        'server.settings.dynamic_timeout' => 'Dynamic Timeout',
        'server.settings.is_metrics_enabled' => 'Metrics',
        'server.settings.sentinel_token' => 'Metrics Token',
        'server.settings.sentinel_metrics_refresh_rate_seconds' => 'Metrics Interval',
        'server.settings.sentinel_metrics_history_days' => 'Metrics History',
        'server.settings.sentinel_push_interval_seconds' => 'Push Interval',
        'server.settings.is_sentinel_enabled' => 'Server API',
        'server.settings.sentinel_custom_url' => 'Coolify URL',
        'server.settings.server_timezone' => 'Server Timezone',
        'server.settings.delete_unused_volumes' => 'Delete Unused Volumes',
        'server.settings.delete_unused_networks' => 'Delete Unused Networks',
    ];

    public function mount(Server $server)
    {
        $this->server = $server;
        $this->timezones = collect(timezone_identifiers_list())->sort()->values()->toArray();
        $this->wildcard_domain = $this->server->settings->wildcard_domain;
        $this->server->settings->docker_cleanup_threshold = $this->server->settings->docker_cleanup_threshold;
        $this->server->settings->docker_cleanup_frequency = $this->server->settings->docker_cleanup_frequency;
        $this->server->settings->delete_unused_volumes = $server->settings->delete_unused_volumes;
        $this->server->settings->delete_unused_networks = $server->settings->delete_unused_networks;
    }

    public function checkSyncStatus()
    {
        $this->server->refresh();
        $this->server->settings->refresh();
    }

    public function regenerateSentinelToken()
    {
        try {
            $this->server->settings->generateSentinelToken();
            $this->server->settings->refresh();
            $this->restartSentinel(notification: false);
            $this->dispatch('success', 'Token regenerated & Sentinel restarted.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function updated($field)
    {
        if ($field === 'server.settings.docker_cleanup_frequency') {
            $frequency = $this->server->settings->docker_cleanup_frequency;
            if (empty($frequency) || ! validate_cron_expression($frequency)) {
                $this->dispatch('error', 'Invalid Cron / Human expression for Docker Cleanup Frequency. Resetting to default 10 minutes.');
                $this->server->settings->docker_cleanup_frequency = '*/10 * * * *';
            }
        }
    }

    public function cloudflareTunnelConfigured()
    {
        $this->serverInstalled();
        $this->dispatch('success', 'Cloudflare Tunnels configured successfully.');
    }

    public function serverInstalled()
    {
        $this->server->refresh();
        $this->server->settings->refresh();
    }

    public function updatedServerSettingsIsBuildServer()
    {
        $this->dispatch('refreshServerShow');
        $this->dispatch('serverRefresh');
        $this->dispatch('proxyStatusUpdated');
    }

    public function updatedServerSettingsIsSentinelEnabled($value)
    {
        $this->validate();
        $this->validate([
            'server.settings.sentinel_custom_url' => 'required|url',
        ]);
        if ($value === false) {
            StopSentinel::dispatch($this->server);
            $this->server->settings->is_metrics_enabled = false;
            $this->server->settings->save();
            $this->server->sentinelHeartbeat(isReset: true);
        } else {
            try {
                StartSentinel::run($this->server);
            } catch (\Throwable $e) {
                return handleError($e, $this);
            }
        }
    }

    public function updatedServerSettingsIsMetricsEnabled()
    {
        $this->restartSentinel();
    }


    public function instantSave()
    {
        try {
            $this->validate();
            refresh_server_connection($this->server->privateKey);
            $this->validateServer(false);

            $this->server->settings->save();
            $this->server->save();
            $this->dispatch('success', 'Server updated.');
            $this->dispatch('refreshServerShow');

            // if ($this->server->isSentinelEnabled()) {
            //     StartSentinel::run($this->server);
            // } else {
            //     StopSentinel::run($this->server);
            //     $this->server->settings->is_metrics_enabled = false;
            //     $this->server->settings->save();
            //     $this->server->sentinelHeartbeat(isReset: true);
            // }
            // if ($this->server->isSentinelEnabled()) {
            //     PullSentinelImageJob::dispatchSync($this->server);
            //     ray('Sentinel is enabled');
            //     if ($this->server->settings->isDirty('is_metrics_enabled')) {
            //         $this->dispatch('reloadWindow');
            //     }
            //     if ($this->server->settings->isDirty('is_sentinel_enabled') && $this->server->settings->is_sentinel_enabled === true) {
            //         ray('Starting sentinel');
            //     }
            // } else {
            //     ray('Sentinel is not enabled');
            //     StopSentinel::dispatch($this->server);
            // }
            $this->server->settings->save();
            // $this->checkPortForServerApi();

        } catch (\Throwable $e) {
            $this->server->settings->refresh();
            return handleError($e, $this);
        }
    }

    public function restartSentinel($notification = true)
    {
        try {
            $this->validate();
            $this->validate([
                'server.settings.sentinel_custom_url' => 'required|url',
            ]);
            $version = get_latest_sentinel_version();
            StartSentinel::run($this->server, $version, true);
            if ($notification) {
                $this->dispatch('success', 'Sentinel started.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function revalidate()
    {
        $this->revalidate = true;
    }

    public function checkLocalhostConnection()
    {
        $this->submit();
        ['uptime' => $uptime, 'error' => $error] = $this->server->validateConnection();
        if ($uptime) {
            $this->dispatch('success', 'Server is reachable.');
            $this->server->settings->is_reachable = true;
            $this->server->settings->is_usable = true;
            $this->server->settings->save();
            $this->dispatch('proxyStatusUpdated');
        } else {
            $this->dispatch('error', 'Server is not reachable.', 'Please validate your configuration and connection.<br><br>Check this <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/openssh">documentation</a> for further help. <br><br>Error: ' . $error);

            return;
        }
    }

    public function validateServer($install = true)
    {
        $this->server->update([
            'validation_logs' => null,
        ]);
        $this->dispatch('init', $install);
    }

    public function submit()
    {
        try {
            if (isCloud() && ! isDev()) {
                $this->validate();
                $this->validate([
                    'server.ip' => 'required',
                ]);
            } else {
                $this->validate();
            }
            $uniqueIPs = Server::all()->reject(function (Server $server) {
                return $server->id === $this->server->id;
            })->pluck('ip')->toArray();
            if (in_array($this->server->ip, $uniqueIPs)) {
                $this->dispatch('error', 'IP address is already in use by another team.');

                return;
            }
            refresh_server_connection($this->server->privateKey);
            $this->server->settings->wildcard_domain = $this->wildcard_domain;
            if ($this->server->settings->force_docker_cleanup) {
                $this->server->settings->docker_cleanup_frequency = $this->server->settings->docker_cleanup_frequency;
            } else {
                $this->server->settings->docker_cleanup_threshold = $this->server->settings->docker_cleanup_threshold;
            }
            $currentTimezone = $this->server->settings->getOriginal('server_timezone');
            $newTimezone = $this->server->settings->server_timezone;
            if ($currentTimezone !== $newTimezone || $currentTimezone === '') {
                $this->server->settings->server_timezone = $newTimezone;
            }
            $this->server->settings->save();
            $this->server->save();

            $this->dispatch('success', 'Server updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function manualCleanup()
    {
        try {
            DockerCleanupJob::dispatch($this->server, true);
            $this->dispatch('success', 'Manual cleanup job started. Depending on the amount of data, this might take a while.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function manualCloudflareConfig()
    {
        $this->server->settings->is_cloudflare_tunnel = true;
        $this->server->settings->save();
        $this->server->refresh();
        $this->dispatch('success', 'Cloudflare Tunnels enabled.');
    }
}
