<?php

namespace App\Livewire\Server;

use App\Actions\Sentinel\PingFluxConnection;
use App\Actions\Sentinel\RenewFluxCertificate;
use App\Actions\Server\InstallSentinelHost;
use App\Actions\Server\RepairSentinelFluxTrust;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
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

    #[Validate(['nullable', 'url'])]
    public ?string $sentinelCustomUrl = null;

    public bool $isSentinelDebugEnabled;

    public ?string $sentinelCustomDockerImage = null;

    /** @var array<string, mixed>|null */
    public ?array $fluxConnection = null;

    public function getListeners()
    {
        $teamId = $this->server->team_id ?? auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},SentinelRestarted" => 'handleSentinelRestarted',
        ];
    }

    public function mount()
    {
        $this->authorize('view', $this->server);
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
            $this->authorize('update', $this->server);
            $this->syncData(true);
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

    public function installHostSentinel(): void
    {
        abort_unless(isDev() && config('constants.sentinel.host_enabled', false), 404);

        try {
            $this->authorize('manageSentinel', $this->server);
            InstallSentinelHost::run($this->server);
            $this->dispatch('success', 'Host Sentinel installed and started.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function refreshFluxConnection(): void
    {
        abort_unless(isDev() && config('constants.sentinel.host_enabled', false), 404);
        $this->authorize('view', $this->server);
        $this->loadFluxConnection();
        $state = data_get($this->fluxConnection, 'status', 'disconnected');
        $this->dispatch('info', "Flux connection state refreshed. Sentinel is {$state}.");
    }

    public function testFluxConnection(): void
    {
        abort_unless(isDev() && config('constants.sentinel.host_enabled', false), 404);

        try {
            $this->authorize('manageSentinel', $this->server);
            $result = PingFluxConnection::run($this->server);
            $version = data_get($result, 'sentinel_version', 'unknown');
            $latency = data_get($result, 'latency_ms', 'unknown');
            $this->dispatch('success', "Flux connection test succeeded. Sentinel {$version} responded in {$latency} ms.");
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function repairFluxTrust(): void
    {
        abort_unless(isDev() && config('constants.sentinel.host_enabled', false), 404);

        try {
            $this->authorize('manageSentinel', $this->server);
            RepairSentinelFluxTrust::run($this->server);
            $this->dispatch('success', 'Sentinel Flux trust repaired.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function renewFluxCertificate(): void
    {
        abort_unless(isDev() && config('constants.sentinel.host_enabled', false), 404);

        try {
            $this->authorize('manageSentinel', $this->server);
            RenewFluxCertificate::run(null, true);
            $this->dispatch('success', 'Flux TLS certificate renewed.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        $this->loadFluxConnection();

        return view('livewire.server.sentinel');
    }

    private function loadFluxConnection(): void
    {
        $this->fluxConnection = isDev() && config('constants.sentinel.host_enabled', false)
            ? Cache::get("flux:connection:{$this->server->uuid}")
            : null;
    }
}
