<?php

namespace App\Livewire\Server;

use App\Actions\Server\ConfigureCloudflared;
use App\Actions\Server\UpdateCloudflared;
use App\Models\Server;
use App\Traits\ListensToTeamChannel;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

class CloudflareTunnel extends Component
{
    use AuthorizesRequests;
    use ListensToTeamChannel;

    public Server $server;

    #[Validate(['required', 'string'])]
    public string $cloudflare_token;

    #[Validate(['required', 'string'])]
    public string $ssh_domain;

    #[Validate(['required', 'boolean'])]
    public bool $isCloudflareTunnelsEnabled;

    public function getListeners()
    {
        return $this->teamChannelListeners([
            'CloudflareTunnelConfigured' => 'refresh',
        ]);
    }

    public function refresh()
    {
        $this->server->refresh();
        $this->isCloudflareTunnelsEnabled = $this->server->settings->is_cloudflare_tunnel;
    }

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            if ($this->server->isLocalhost()) {
                return redirect()->route('server.show', ['server_uuid' => $server_uuid]);
            }
            $this->isCloudflareTunnelsEnabled = $this->server->settings->is_cloudflare_tunnel;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function toggleCloudflareTunnels()
    {
        try {
            $this->authorize('update', $this->server);
            remote_process(['docker rm -f coolify-cloudflared'], $this->server, false, 10);
            $this->isCloudflareTunnelsEnabled = false;
            $this->server->settings->is_cloudflare_tunnel = false;
            $this->server->settings->save();
            auditLog('ui.server.cloudflare_tunnel.disabled', $this->auditContext());
            if ($this->server->ip_previous) {
                $this->server->update(['ip' => $this->server->ip_previous]);
                $this->dispatch('success', 'Cloudflare Tunnel disabled.<br><br>Manually updated the server IP address to its previous IP address.');
            } else {
                $this->dispatch('warning', 'Cloudflare Tunnel disabled. Action required: Update the server IP address to its real IP address in the Advanced settings.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function manualCloudflareConfig()
    {
        try {
            $this->authorize('update', $this->server);
            $this->isCloudflareTunnelsEnabled = true;
            $this->server->settings->is_cloudflare_tunnel = true;
            $this->server->settings->save();
            auditLog('ui.server.cloudflare_tunnel.enabled', $this->auditContext());
            $this->server->refresh();
            $this->dispatch('success', 'Cloudflare Tunnel enabled.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function automatedCloudflareConfig()
    {
        try {
            $this->authorize('update', $this->server);
            if (str($this->ssh_domain)->contains('https://')) {
                $this->ssh_domain = str($this->ssh_domain)->replace('https://', '')->replace('http://', '')->trim();
                $this->ssh_domain = str($this->ssh_domain)->replace('/', '');
            }
            $activity = ConfigureCloudflared::run($this->server, $this->cloudflare_token, $this->ssh_domain);
            auditLog('ui.server.cloudflare_tunnel.configuration_started', $this->auditContext([
                'ssh_domain' => (string) $this->ssh_domain,
            ]));
            $this->dispatch('activityMonitor', $activity->id);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function updateCloudflareTunnel()
    {
        try {
            $this->authorize('update', $this->server);
            $activity = UpdateCloudflared::run($this->server);
            auditLog('ui.server.cloudflare_tunnel.update_started', $this->auditContext());
            $this->dispatch('cloudflare-tunnel-update-started');
            $this->dispatch('activityMonitor', $activity->id);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.cloudflare-tunnel');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function auditContext(array $context = []): array
    {
        return array_merge([
            'team_id' => $this->server->team_id,
            'server_uuid' => $this->server->uuid,
            'server_name' => $this->server->name,
        ], $context);
    }
}
