<?php

namespace App\Livewire\Server;

use App\Actions\Server\StartSentinel;
use App\Actions\Server\StopSentinel;
use App\Events\ServerReachabilityChanged;
use App\Models\Server;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public string $name;

    public ?string $description = null;

    public string $ip;

    public string $user;

    public string $port;

    public ?string $validationLogs = null;

    public ?string $wildcardDomain = null;

    public bool $isReachable;

    public bool $isUsable;

    public bool $isSwarmManager;

    public bool $isSwarmWorker;

    public bool $isBuildServer;

    #[Locked]
    public bool $isBuildServerLocked = false;

    public bool $isMetricsEnabled;

    public string $sentinelToken;

    public ?string $sentinelUpdatedAt = null;

    public int $sentinelMetricsRefreshRateSeconds;

    public int $sentinelMetricsHistoryDays;

    public int $sentinelPushIntervalSeconds;

    public ?string $sentinelCustomUrl = null;

    public bool $isSentinelEnabled;

    public bool $isSentinelDebugEnabled;

    public ?string $sentinelCustomDockerImage = null;

    public string $serverTimezone;

    public ?string $hetznerServerStatus = null;

    public bool $hetznerServerManuallyStarted = false;

    public bool $isValidating = false;

    public function getListeners()
    {
        $teamId = $this->server->team_id ?? auth()->user()->currentTeam()->id;

        return [
            'refreshServerShow' => 'refresh',
            'refreshServer' => '$refresh',
            "echo-private:team.{$teamId},SentinelRestarted" => 'handleSentinelRestarted',
            "echo-private:team.{$teamId},ServerValidated" => 'handleServerValidated',
        ];
    }

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'ip' => 'required',
            'user' => 'required',
            'port' => 'required',
            'validationLogs' => 'nullable',
            'wildcardDomain' => 'nullable|url',
            'isReachable' => 'required',
            'isUsable' => 'required',
            'isSwarmManager' => 'required',
            'isSwarmWorker' => 'required',
            'isBuildServer' => 'required',
            'isMetricsEnabled' => 'required',
            'sentinelToken' => 'required',
            'sentinelUpdatedAt' => 'nullable',
            'sentinelMetricsRefreshRateSeconds' => 'required|integer|min:1',
            'sentinelMetricsHistoryDays' => 'required|integer|min:1',
            'sentinelPushIntervalSeconds' => 'required|integer|min:10',
            'sentinelCustomUrl' => 'nullable|url',
            'isSentinelEnabled' => 'required',
            'isSentinelDebugEnabled' => 'required',
            'serverTimezone' => 'required',
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'ip.required' => 'The IP Address field is required.',
                'user.required' => 'The User field is required.',
                'port.required' => 'The Port field is required.',
                'wildcardDomain.url' => 'The Wildcard Domain must be a valid URL.',
                'sentinelToken.required' => 'The Sentinel Token field is required.',
                'sentinelMetricsRefreshRateSeconds.required' => 'The Metrics Refresh Rate field is required.',
                'sentinelMetricsRefreshRateSeconds.integer' => 'The Metrics Refresh Rate must be an integer.',
                'sentinelMetricsRefreshRateSeconds.min' => 'The Metrics Refresh Rate must be at least 1 second.',
                'sentinelMetricsHistoryDays.required' => 'The Metrics History Days field is required.',
                'sentinelMetricsHistoryDays.integer' => 'The Metrics History Days must be an integer.',
                'sentinelMetricsHistoryDays.min' => 'The Metrics History Days must be at least 1 day.',
                'sentinelPushIntervalSeconds.required' => 'The Push Interval field is required.',
                'sentinelPushIntervalSeconds.integer' => 'The Push Interval must be an integer.',
                'sentinelPushIntervalSeconds.min' => 'The Push Interval must be at least 10 seconds.',
                'sentinelCustomUrl.url' => 'The Custom Sentinel URL must be a valid URL.',
                'serverTimezone.required' => 'The Server Timezone field is required.',
            ]
        );
    }

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->syncData();
            if (! $this->server->isEmpty()) {
                $this->isBuildServerLocked = true;
            }
            // Load saved Hetzner status and validation state
            $this->hetznerServerStatus = $this->server->hetzner_server_status;
            $this->isValidating = $this->server->is_validating ?? false;

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

            $this->authorize('update', $this->server);
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
            $this->sentinelUpdatedAt = $this->server->sentinel_updated_at;
            $this->serverTimezone = $this->server->settings->server_timezone;
            $this->isValidating = $this->server->is_validating ?? false;
        }
    }

    public function refresh()
    {
        $this->syncData();
    }

    public function handleSentinelRestarted($event)
    {
        // Only refresh if the event is for this server
        if (isset($event['serverUuid']) && $event['serverUuid'] === $this->server->uuid) {
            $this->server->refresh();
            $this->syncData();
            $this->dispatch('success', 'Sentinel has been restarted successfully.');
        }
    }

    public function validateServer($install = true)
    {
        try {
            $this->authorize('update', $this->server);
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
        } else {
            $this->dispatch('error', 'Server is not reachable.', 'Please validate your configuration and connection.<br><br>Check this <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/openssh">documentation</a> for further help. <br><br>Error: '.$error);

            return;
        }
    }

    public function restartSentinel()
    {
        try {
            $this->authorize('manageSentinel', $this->server);
            $customImage = isDev() ? $this->sentinelCustomDockerImage : null;
            $this->server->restartSentinel($customImage);
            $this->dispatch('info', 'Restarting Sentinel.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }

    }

    public function updatedIsSentinelDebugEnabled($value)
    {
        try {
            $this->submit();
            $this->restartSentinel();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function updatedIsMetricsEnabled($value)
    {
        try {
            $this->submit();
            $this->restartSentinel();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function updatedIsBuildServer($value)
    {
        try {
            $this->authorize('update', $this->server);
            if ($value === true && $this->isSentinelEnabled) {
                $this->isSentinelEnabled = false;
                $this->isMetricsEnabled = false;
                $this->isSentinelDebugEnabled = false;
                StopSentinel::dispatch($this->server);
                $this->dispatch('info', 'Sentinel has been disabled as build servers cannot run Sentinel.');
            }
            $this->submit();
            // Dispatch event to refresh the navbar
            $this->dispatch('refreshServerShow');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function updatedIsSentinelEnabled($value)
    {
        try {
            $this->authorize('manageSentinel', $this->server);
            if ($value === true) {
                if ($this->isBuildServer) {
                    $this->isSentinelEnabled = false;
                    $this->dispatch('error', 'Sentinel cannot be enabled on build servers.');

                    return;
                }
                $customImage = isDev() ? $this->sentinelCustomDockerImage : null;
                StartSentinel::run($this->server, true, null, $customImage);
            } else {
                $this->isMetricsEnabled = false;
                $this->isSentinelDebugEnabled = false;
                StopSentinel::dispatch($this->server);
            }
            $this->submit();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function regenerateSentinelToken()
    {
        try {
            $this->authorize('manageSentinel', $this->server);
            $this->server->settings->generateSentinelToken();
            $this->dispatch('success', 'Token regenerated. Restarting Sentinel.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        try {
            $this->syncData(true);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function checkHetznerServerStatus(bool $manual = false)
    {
        try {
            if (! $this->server->hetzner_server_id || ! $this->server->cloudProviderToken) {
                $this->dispatch('error', 'This server is not associated with a Hetzner Cloud server or token.');

                return;
            }

            $hetznerService = new \App\Services\HetznerService($this->server->cloudProviderToken->token);
            $serverData = $hetznerService->getServer($this->server->hetzner_server_id);

            $this->hetznerServerStatus = $serverData['status'] ?? null;

            // Save status to database without triggering model events
            if ($this->server->hetzner_server_status !== $this->hetznerServerStatus) {
                $this->server->hetzner_server_status = $this->hetznerServerStatus;
                $this->server->update(['hetzner_server_status' => $this->hetznerServerStatus]);
            }
            if ($manual) {
                $this->dispatch('success', 'Server status refreshed: '.ucfirst($this->hetznerServerStatus ?? 'unknown'));
            }

            // If Hetzner server is off but Coolify thinks it's still reachable, update Coolify's state
            if ($this->hetznerServerStatus === 'off' && $this->server->settings->is_reachable) {
                ['uptime' => $uptime, 'error' => $error] = $this->server->validateConnection();
                if ($uptime) {
                    $this->dispatch('success', 'Server is reachable.');
                    $this->server->settings->is_reachable = $this->isReachable = true;
                    $this->server->settings->is_usable = $this->isUsable = true;
                    $this->server->settings->save();
                    ServerReachabilityChanged::dispatch($this->server);
                } else {
                    $this->dispatch('error', 'Server is not reachable.', 'Please validate your configuration and connection.<br><br>Check this <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/openssh">documentation</a> for further help. <br><br>Error: '.$error);

                    return;
                }
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function handleServerValidated($event = null)
    {
        // Check if event is for this server
        if ($event && isset($event['serverUuid']) && $event['serverUuid'] !== $this->server->uuid) {
            return;
        }

        // Refresh server data
        $this->server->refresh();
        $this->syncData();

        // Update validation state
        $this->isValidating = $this->server->is_validating ?? false;
        $this->dispatch('refreshServerShow');
        $this->dispatch('refreshServer');
    }

    public function startHetznerServer()
    {
        try {
            if (! $this->server->hetzner_server_id || ! $this->server->cloudProviderToken) {
                $this->dispatch('error', 'This server is not associated with a Hetzner Cloud server or token.');

                return;
            }

            $hetznerService = new \App\Services\HetznerService($this->server->cloudProviderToken->token);
            $hetznerService->powerOnServer($this->server->hetzner_server_id);

            $this->hetznerServerStatus = 'starting';
            $this->server->update(['hetzner_server_status' => 'starting']);
            $this->hetznerServerManuallyStarted = true; // Set flag to trigger auto-validation when running
            $this->dispatch('success', 'Hetzner server is starting...');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->syncData(true);
            $this->dispatch('success', 'Server settings updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.show');
    }
}
