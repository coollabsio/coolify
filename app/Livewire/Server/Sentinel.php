<?php

namespace App\Livewire\Server;

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

    public string $sentinelStatus = 'out_of_sync';

    public ?int $sentinelRestartRequestedAt = null;

    #[Validate(['nullable', 'url'])]
    public ?string $sentinelCustomUrl = null;

    public bool $isSentinelDebugEnabled;

    public ?string $sentinelCustomDockerImage = null;

    public function getListeners()
    {
        $teamId = $this->server->team_id ?? auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},SentinelRestarted" => 'handleSentinelRestarted',
            "echo-private:team.{$teamId},SentinelSynchronized" => 'handleSentinelSynchronized',
        ];
    }

    public function mount()
    {
        $this->syncData();
    }

    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->validate();
            $this->server->settings->is_metrics_enabled = $this->isMetricsEnabled;
            $this->server->settings->sentinel_token = $this->sentinelToken;
            $this->server->settings->sentinel_custom_url = $this->sentinelCustomUrl;
            $this->server->settings->is_sentinel_debug_enabled = $this->isSentinelDebugEnabled;
            $this->server->settings->save();
        } else {
            $this->isMetricsEnabled = $this->server->settings->is_metrics_enabled;
            $this->sentinelToken = $this->server->settings->sentinel_token;
            $this->sentinelCustomUrl = $this->server->settings->sentinel_custom_url;
            $this->isSentinelDebugEnabled = $this->server->settings->is_sentinel_debug_enabled;
            $this->sentinelUpdatedAt = $this->server->sentinel_updated_at;
            $this->sentinelStatus = $this->server->sentinelStatus();
        }
    }

    public function handleSentinelRestarted($event)
    {
        if ($event['serverUuid'] === $this->server->uuid) {
            $this->server->refresh();
            // Only refresh display-only state; never re-sync text-input properties
            // (would clobber any unsaved typing — see coolify#6062 / #6354 / #9695).
            $this->sentinelUpdatedAt = $this->server->sentinel_updated_at;
            $this->sentinelStatus = $this->server->sentinelStatus();
            $this->sentinelRestartRequestedAt = null;
            $this->dispatch('success', 'Sentinel has been restarted successfully.');
        }
    }

    public function handleSentinelSynchronized($event): void
    {
        if ($event['serverUuid'] === $this->server->uuid) {
            $this->server->refresh();
            $this->sentinelUpdatedAt = $this->server->sentinel_updated_at;
            $this->sentinelStatus = 'in_sync';
            $this->sentinelRestartRequestedAt = null;
        }
    }

    public function refreshSentinelStatus(): void
    {
        if ($this->sentinelStatus === 'restarting'
            && $this->sentinelRestartRequestedAt !== null
            && $this->sentinelRestartRequestedAt > now()->subSeconds($this->server->firstSentinelReportTimeoutSeconds())->timestamp) {
            return;
        }

        $this->server->refresh();
        $this->sentinelUpdatedAt = $this->server->sentinel_updated_at;
        $this->sentinelStatus = $this->server->sentinelStatus();
    }

    private function setSentinelRestarting(): void
    {
        $this->sentinelStatus = 'restarting';
        $this->sentinelRestartRequestedAt = now()->timestamp;
        $this->dispatch(
            'sentinel-status-changed',
            outOfSync: false,
            expiresInMilliseconds: $this->server->firstSentinelReportTimeoutSeconds() * 1000,
        );
        $this->dispatch('sentinel-restart-requested');
    }

    public function restartSentinel()
    {
        try {
            $this->authorize('manageSentinel', $this->server);
            $this->setSentinelRestarting();
            $customImage = isDev() ? $this->sentinelCustomDockerImage : null;
            $this->server->restartSentinel($customImage);
            auditLog('ui.server.sentinel.restarted', $this->auditContext());
            $this->dispatch('info', 'Restarting Sentinel.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function regenerateSentinelToken()
    {
        try {
            $this->authorize('manageSentinel', $this->server);
            $this->setSentinelRestarting();
            $this->server->settings->generateSentinelToken();
            auditLog('ui.server.sentinel.token_regenerated', $this->auditContext());
            $this->dispatch('success', 'Token regenerated. Restarting Sentinel.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function restoreDefaultConfiguration(?string $password = null): void
    {
        try {
            $this->authorize('manageSentinel', $this->server);

            $this->server->settings->restoreDefaultSentinelConfiguration();

            $this->sentinelCustomDockerImage = null;
            $this->syncData();
            $this->dispatch('sentinel-defaults-restored');
            $this->setSentinelRestarting();
            $this->server->restartSentinel();
            auditLog('ui.server.sentinel.defaults_restored', $this->auditContext());
            $this->dispatch('success', 'Default Sentinel configuration restored. Restarting Sentinel.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->server);
            $this->setSentinelRestarting();
            $this->syncData(true);
            auditLog('ui.server.sentinel.updated', $this->auditContext([
                'changed_fields' => ['is_metrics_enabled', 'sentinel_token', 'sentinel_custom_url', 'is_sentinel_debug_enabled'],
            ]));
            $this->dispatch('success', 'Sentinel settings updated. Restarting Sentinel.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->server);
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

    private function auditContext(array $context = []): array
    {
        return array_merge([
            'team_id' => $this->server->team_id,
            'server_uuid' => $this->server->uuid,
            'server_name' => $this->server->name,
        ], $context);
    }
}
