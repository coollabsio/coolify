<?php

namespace App\Livewire\Server;

use App\Actions\Server\StartSentinel;
use App\Actions\Server\StopSentinel;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Sentinel extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public bool $isMetricsEnabled;

    #[Validate(['required', 'string', 'max:500', 'regex:/\A[a-zA-Z0-9._\-+=\/]+\z/'])]
    public string $sentinelToken;

    public ?string $sentinelUpdatedAt = null;

    #[Validate(['required', 'integer', 'min:1'])]
    public int|string $sentinelMetricsRefreshRateSeconds;

    #[Validate(['required', 'integer', 'min:1'])]
    public int|string $sentinelMetricsHistoryDays;

    #[Validate(['required', 'integer', 'min:10'])]
    public int|string $sentinelPushIntervalSeconds;

    #[Validate(['nullable', 'url'])]
    public ?string $sentinelCustomUrl = null;

    public bool $isSentinelEnabled;

    public bool $isSentinelDebugEnabled;

    public ?string $sentinelCustomDockerImage = null;

    public function getListeners()
    {
        $teamId = $this->server->team_id ?? auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},SentinelRestarted" => 'handleSentinelRestarted',
        ];
    }

    public function mount()
    {
        $this->syncData();
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->authorize('update', $this->server);
            $this->validate();
            $this->server->settings->is_metrics_enabled = $this->isMetricsEnabled;
            $this->server->settings->sentinel_token = $this->sentinelToken;
            $this->server->settings->sentinel_metrics_refresh_rate_seconds = $this->sentinelMetricsRefreshRateSeconds;
            $this->server->settings->sentinel_metrics_history_days = $this->sentinelMetricsHistoryDays;
            $this->server->settings->sentinel_push_interval_seconds = $this->sentinelPushIntervalSeconds;
            $this->server->settings->sentinel_custom_url = $this->sentinelCustomUrl;
            $this->server->settings->is_sentinel_enabled = $this->isSentinelEnabled;
            $this->server->settings->is_sentinel_debug_enabled = $this->isSentinelDebugEnabled;
            $this->server->settings->save();
        } else {
            $this->isMetricsEnabled = $this->server->settings->is_metrics_enabled;
            $this->sentinelToken = $this->server->settings->sentinel_token;
            $this->sentinelMetricsRefreshRateSeconds = $this->server->settings->sentinel_metrics_refresh_rate_seconds;
            $this->sentinelMetricsHistoryDays = $this->server->settings->sentinel_metrics_history_days;
            $this->sentinelPushIntervalSeconds = $this->server->settings->sentinel_push_interval_seconds;
            $this->sentinelCustomUrl = $this->server->settings->sentinel_custom_url;
            $this->isSentinelEnabled = $this->server->settings->is_sentinel_enabled;
            $this->isSentinelDebugEnabled = $this->server->settings->is_sentinel_debug_enabled;
            $this->sentinelUpdatedAt = $this->server->sentinel_updated_at;
        }
    }

    public function handleSentinelRestarted($event)
    {
        if ($event['serverUuid'] === $this->server->uuid) {
            $this->server->refresh();
            // Only refresh display-only state; never re-sync text-input properties
            // (would clobber any unsaved typing — see coolify#6062 / #6354 / #9695).
            $this->sentinelUpdatedAt = $this->server->sentinel_updated_at;
            $this->dispatch('success', 'Sentinel has been restarted successfully.');
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

    public function toggleSentinel(): void
    {
        try {
            $this->authorize('manageSentinel', $this->server);
            if (! $this->isSentinelEnabled) {
                if ($this->server->isBuildServer()) {
                    $this->dispatch('error', 'Sentinel cannot be enabled on build servers.');

                    return;
                }
                $this->isSentinelEnabled = true;
                $customImage = isDev() ? $this->sentinelCustomDockerImage : null;
                StartSentinel::run($this->server, true, null, $customImage);
            } else {
                $this->isSentinelEnabled = false;
                $this->isMetricsEnabled = false;
                $this->isSentinelDebugEnabled = false;
                StopSentinel::dispatch($this->server);
            }
            $this->submit();
            $this->dispatch('refreshServerShow');
        } catch (\Throwable $e) {
            handleError($e, $this);
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

    public function submit()
    {
        try {
            $this->syncData(true);
            $this->dispatch('success', 'Sentinel settings updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        try {
            $this->syncData(true);
            $this->restartSentinel();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.sentinel');
    }
}
