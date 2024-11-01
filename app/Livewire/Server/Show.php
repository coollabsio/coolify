<?php

namespace App\Livewire\Server;

use App\Actions\Server\StartSentinel;
use App\Actions\Server\StopSentinel;
use App\Models\Server;
use Livewire\Attributes\Rule;
use Livewire\Component;

class Show extends Component
{
    public Server $server;

    #[Rule(['required'])]
    public string $name;

    #[Rule(['nullable'])]
    public ?string $description;

    #[Rule(['required'])]
    public string $ip;

    #[Rule(['required'])]
    public string $user;

    #[Rule(['required'])]
    public string $port;

    #[Rule(['nullable'])]
    public ?string $validationLogs = null;

    #[Rule(['nullable', 'url'])]
    public ?string $wildcardDomain;

    #[Rule(['required'])]
    public bool $isReachable;

    #[Rule(['required'])]
    public bool $isUsable;

    #[Rule(['required'])]
    public bool $isSwarmManager;

    #[Rule(['required'])]
    public bool $isSwarmWorker;

    #[Rule(['required'])]
    public bool $isBuildServer;

    #[Rule(['required'])]
    public bool $isMetricsEnabled;

    #[Rule(['required'])]
    public string $sentinelToken;

    #[Rule(['nullable'])]
    public ?string $sentinelUpdatedAt;

    #[Rule(['required', 'integer', 'min:1'])]
    public int $sentinelMetricsRefreshRateSeconds;

    #[Rule(['required', 'integer', 'min:1'])]
    public int $sentinelMetricsHistoryDays;

    #[Rule(['required', 'integer', 'min:10'])]
    public int $sentinelPushIntervalSeconds;

    #[Rule(['nullable', 'url'])]
    public ?string $sentinelCustomUrl;

    #[Rule(['required'])]
    public bool $isSentinelEnabled;

    #[Rule(['required'])]
    public bool $isSentinelDebugEnabled;

    #[Rule(['required'])]
    public string $serverTimezone;

    public array $timezones;

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},CloudflareTunnelConfigured" => '$refresh',
            'refreshServerShow' => '$refresh',
        ];
    }

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->timezones = collect(timezone_identifiers_list())->sort()->values()->toArray();
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->server->name = $this->name;
            $this->server->description = $this->description;
            $this->server->ip = $this->ip;
            $this->server->user = $this->user;
            $this->server->port = $this->port;
            $this->server->validation_logs = $this->validationLogs;
            $this->server->save();

            $this->server->settings->is_swarm_manager = $this->isSwarmManager;
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
            $this->server->settings->server_timezone = $this->serverTimezone;
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
        $this->server->settings->is_sentinel_debug_enabled = $value;
        $this->server->settings->save();
        $this->restartSentinel();
    }

    public function updatedIsMetricsEnabled($value)
    {
        $this->server->settings->is_metrics_enabled = $value;
        $this->server->settings->save();
        $this->restartSentinel();
    }

    public function updatedIsSentinelEnabled($value)
    {
        $this->server->settings->is_sentinel_enabled = $value;
        if ($value === true) {
            StartSentinel::run($this->server, true);
        } else {
            $this->server->settings->is_metrics_enabled = false;
            $this->server->settings->is_sentinel_debug_enabled = false;
            StopSentinel::dispatch($this->server);
        }
        $this->server->settings->save();

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
