<?php

namespace App\Livewire\Server;

use App\Actions\Server\StartSentinel;
use App\Actions\Server\StopSentinel;
use App\Events\ServerReachabilityChanged;
use App\Models\Server;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Show extends Component
{
    public Server $server;

    #[Validate(['required'])]
    public string $name;

    #[Validate(['nullable'])]
    public ?string $description = null;

    #[Validate(['required'])]
    public string $ip;

    #[Validate(['required'])]
    public string $user;

    #[Validate(['required'])]
    public string $port;

    #[Validate(['nullable'])]
    public ?string $validationLogs = null;

    #[Validate(['nullable', 'url'])]
    public ?string $wildcardDomain = null;

    #[Validate(['required'])]
    public bool $isReachable;

    #[Validate(['required'])]
    public bool $isUsable;

    #[Validate(['required'])]
    public bool $isSwarmManager;

    #[Validate(['required'])]
    public bool $isSwarmWorker;

    #[Validate(['required'])]
    public bool $isBuildServer;

    #[Validate(['required'])]
    public bool $isMetricsEnabled;

    #[Validate(['required'])]
    public string $sentinelToken;

    #[Validate(['nullable'])]
    public ?string $sentinelUpdatedAt = null;

    #[Validate(['required', 'integer', 'min:1'])]
    public int $sentinelMetricsRefreshRateSeconds;

    #[Validate(['required', 'integer', 'min:1'])]
    public int $sentinelMetricsHistoryDays;

    #[Validate(['required', 'integer', 'min:10'])]
    public int $sentinelPushIntervalSeconds;

    #[Validate(['nullable', 'url'])]
    public ?string $sentinelCustomUrl = null;

    #[Validate(['required'])]
    public bool $isSentinelEnabled;

    #[Validate(['required'])]
    public bool $isSentinelDebugEnabled;

    #[Validate(['required'])]
    public string $serverTimezone;

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},CloudflareTunnelConfigured" => 'refresh',
            'refreshServerShow' => 'refresh',
        ];
    }

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    #[Computed]
    public function timezones(): array
    {
        return collect(timezone_identifiers_list())
            ->sort()
            ->values()
            ->toArray();
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();

            if (Server::where('team_id', currentTeam()->id)
                ->where('ip', $this->ip)
                ->where('id', '!=', $this->server->id)
                ->exists()) {
                $this->ip = $this->server->ip;
                throw new \Exception('This IP/Domain is already in use by another server in your team.');
            }

            $this->server->name = $this->name;
            $this->server->description = $this->description;
            $this->server->ip = $this->ip;
            $this->server->user = $this->user;
            $this->server->port = $this->port;
            $this->server->validation_logs = $this->validationLogs;
            $this->server->save();

            $this->server->settings->is_swarm_manager = $this->isSwarmManager;
            $this->server->settings->wildcard_domain = $this->wildcardDomain;
            $this->server->settings->is_swarm_worker = $this->isSwarmWorker;
            $this->server->settings->is_build_server = $this->isBuildServer;
            $this->server->settings->is_metrics_enabled = $this->isMetricsEnabled;
            $this->server->settings->sentinel_token = $this->sentinelToken;
            $this->server->settings->sentinel_metrics_refresh_rate_seconds = $this->sentinelMetricsRefreshRateSeconds;
            $this->server->settings->sentinel_metrics_history_days = $this->sentinelMetricsHistoryDays;
            $this->server->settings->sentinel_push_interval_seconds = $this->sentinelPushIntervalSeconds;
            $this->server->settings->sentinel_custom_url = $this->sentinelCustomUrl;
            $this->server->settings->is_sentinel_enabled = $this->isSentinelEnabled;
            $this->server->settings->is_sentinel_debug_enabled = $this->isSentinelDebugEnabled;

            if (! validate_timezone($this->serverTimezone)) {
                $this->serverTimezone = config('app.timezone');
                throw new \Exception('Invalid timezone.');
            } else {
                $this->server->settings->server_timezone = $this->serverTimezone;
            }

            $this->server->settings->save();
        } else {
            $this->name = $this->server->name;
            $this->description = $this->server->description;
            $this->ip = $this->server->ip;
            $this->user = $this->server->user;
            $this->port = $this->server->port;

            $this->wildcardDomain = $this->server->settings->wildcard_domain;
            $this->isReachable = $this->server->settings->is_reachable;
            $this->isUsable = $this->server->settings->is_usable;
            $this->isSwarmManager = $this->server->settings->is_swarm_manager;
            $this->isSwarmWorker = $this->server->settings->is_swarm_worker;
            $this->isBuildServer = $this->server->settings->is_build_server;
            $this->isMetricsEnabled = $this->server->settings->is_metrics_enabled;
            $this->sentinelToken = $this->server->settings->sentinel_token;
            $this->sentinelMetricsRefreshRateSeconds = $this->server->settings->sentinel_metrics_refresh_rate_seconds;
            $this->sentinelMetricsHistoryDays = $this->server->settings->sentinel_metrics_history_days;
            $this->sentinelPushIntervalSeconds = $this->server->settings->sentinel_push_interval_seconds;
            $this->sentinelCustomUrl = $this->server->settings->sentinel_custom_url;
            $this->isSentinelEnabled = $this->server->settings->is_sentinel_enabled;
            $this->isSentinelDebugEnabled = $this->server->settings->is_sentinel_debug_enabled;
            $this->sentinelUpdatedAt = $this->server->settings->updated_at;
            $this->serverTimezone = $this->server->settings->server_timezone;
        }
    }

    public function refresh()
    {
        $this->syncData();
        $this->dispatch('$refresh');
    }

    public function validateServer($install = true)
    {
        try {
            $this->validationLogs = $this->server->validation_logs = null;
            $this->server->save();
            $this->dispatch('init', $install);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function checkLocalhostConnection()
    {
        $this->syncData(true);
        ['uptime' => $uptime, 'error' => $error] = $this->server->validateConnection();
        if ($uptime) {
            $this->dispatch('success', 'Server is reachable.');
            $this->server->settings->is_reachable = $this->isReachable = true;
            $this->server->settings->is_usable = $this->isUsable = true;
            $this->server->settings->save();
            ServerReachabilityChanged::dispatch($this->server);
            $this->dispatch('proxyStatusUpdated');
        } else {
            $this->dispatch('error', 'Server is not reachable.', 'Please validate your configuration and connection.<br><br>Check this <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/openssh">documentation</a> for further help. <br><br>Error: '.$error);

            return;
        }
    }

    public function restartSentinel()
    {
        $this->server->restartSentinel();
        $this->dispatch('success', 'Sentinel restarted.');
    }

    public function updatedIsSentinelDebugEnabled($value)
    {
        $this->submit();
        $this->restartSentinel();
    }

    public function updatedIsMetricsEnabled($value)
    {
        $this->submit();
        $this->restartSentinel();
    }

    public function updatedIsSentinelEnabled($value)
    {
        if ($value === true) {
            StartSentinel::run($this->server, true);
        } else {
            $this->isMetricsEnabled = false;
            $this->isSentinelDebugEnabled = false;
            StopSentinel::dispatch($this->server);
        }
        $this->submit();

    }

    public function regenerateSentinelToken()
    {
        try {
            $this->server->settings->generateSentinelToken();
            $this->dispatch('success', 'Token regenerated & Sentinel restarted.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        $this->submit();
    }

    public function submit()
    {
        try {
            $this->syncData(true);
            $this->dispatch('success', 'Server updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.show');
    }
}
