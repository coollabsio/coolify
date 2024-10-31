<?php

namespace App\Livewire\Server;

use App\Actions\Server\StartSentinel;
use App\Actions\Server\StopSentinel;
use App\Models\Server;
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
        'wildcard_domain' => 'nullable|url',
        'server.settings.is_reachable' => 'required',
        'server.settings.is_swarm_manager' => 'required|boolean',
        'server.settings.is_swarm_worker' => 'required|boolean',
        'server.settings.is_build_server' => 'required|boolean',
        'server.settings.is_metrics_enabled' => 'required|boolean',
        'server.settings.sentinel_token' => 'required',
        'server.settings.sentinel_metrics_refresh_rate_seconds' => 'required|integer|min:1',
        'server.settings.sentinel_metrics_history_days' => 'required|integer|min:1',
        'server.settings.sentinel_push_interval_seconds' => 'required|integer|min:10',
        'server.settings.sentinel_custom_url' => 'nullable|url',
        'server.settings.is_sentinel_enabled' => 'required|boolean',
        'server.settings.is_sentinel_debug_enabled' => 'required|boolean',
        'server.settings.server_timezone' => 'required|string|timezone',
    ];

    protected $validationAttributes = [
        'server.name' => 'Name',
        'server.description' => 'Description',
        'server.ip' => 'IP address/Domain',
        'server.user' => 'User',
        'server.port' => 'Port',
        'server.settings.is_reachable' => 'Is reachable',
        'server.settings.is_swarm_manager' => 'Swarm Manager',
        'server.settings.is_swarm_worker' => 'Swarm Worker',
        'server.settings.is_build_server' => 'Build Server',
        'server.settings.is_metrics_enabled' => 'Metrics',
        'server.settings.sentinel_token' => 'Metrics Token',
        'server.settings.sentinel_metrics_refresh_rate_seconds' => 'Metrics Interval',
        'server.settings.sentinel_metrics_history_days' => 'Metrics History',
        'server.settings.sentinel_push_interval_seconds' => 'Push Interval',
        'server.settings.is_sentinel_enabled' => 'Server API',
        'server.settings.is_sentinel_debug_enabled' => 'Debug',
        'server.settings.sentinel_custom_url' => 'Coolify URL',
        'server.settings.server_timezone' => 'Server Timezone',
    ];

    public function mount(Server $server)
    {
        $this->server = $server;
        $this->timezones = collect(timezone_identifiers_list())->sort()->values()->toArray();
        $this->wildcard_domain = $this->server->settings->wildcard_domain;
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
            // $this->restartSentinel(notification: false);
            $this->dispatch('success', 'Token regenerated & Sentinel restarted.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function updated($field)
    {
        if ($field === 'server.settings.docker_cleanup_frequency') {
            $frequency = $this->server->settings->docker_cleanup_frequency;
            if (! validate_cron_expression($frequency)) {
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

    public function updatedServerSettingsIsSentinelDebugEnabled()
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
        } catch (\Throwable $e) {
            $this->server->settings->refresh();

            return handleError($e, $this);
        } finally {
        }
    }

    public function saveSentinel()
    {
        try {
            $this->validate();
            $this->server->settings->save();
            $this->dispatch('success', 'Sentinel updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->checkSyncStatus();
        }
    }

    public function restartSentinel($notification = true)
    {
        try {
            $this->validate();
            $this->validate([
                'server.settings.sentinel_custom_url' => 'required|url',
            ]);
            $this->server->settings->save();
            $this->server->restartSentinel(async: false);
            if ($notification) {
                $this->dispatch('success', 'Sentinel restarted.');
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
            $this->dispatch('error', 'Server is not reachable.', 'Please validate your configuration and connection.<br><br>Check this <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/openssh">documentation</a> for further help. <br><br>Error: '.$error);

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
}
