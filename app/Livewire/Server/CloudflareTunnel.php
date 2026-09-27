<?php

namespace App\Livewire\Server;

use App\Actions\Server\ConfigureCloudflared;
use App\Models\Server;
use App\Support\CloudflareHttpTunnel;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

class CloudflareTunnel extends Component
{
    use AuthorizesRequests;

    public Server $server;

    #[Validate(['required', 'string'])]
    public string $cloudflare_token = '';

    #[Validate(['required', 'string'])]
    public string $ssh_domain = '';

    #[Validate(['required', 'boolean'])]
    public bool $isCloudflareTunnelsEnabled = false;

    public bool $isCloudflareHttpTunnelEnabled = false;

    public string $httpCname = '';

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},CloudflareTunnelConfigured" => 'refresh',
        ];
    }

    public function refresh()
    {
        $this->server->refresh();
        $this->server->load('settings');
        $this->isCloudflareTunnelsEnabled = $this->server->settings->is_cloudflare_tunnel;
        $this->hydrateHttpOrigin();
    }

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->isCloudflareTunnelsEnabled = $this->server->settings->is_cloudflare_tunnel;
            $this->hydrateHttpOrigin();
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
            $this->dispatch('activityMonitor', $activity->id);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function saveHttpOrigin(): void
    {
        try {
            $this->authorize('update', $this->server);
            $cname = CloudflareHttpTunnel::cnameTarget(null, $this->normalizedHttpCname());
            $this->server->settings->is_cloudflare_http_tunnel = true;
            $this->server->settings->cloudflare_http_tunnel_cname = $cname;
            $this->server->settings->save();
            $this->refresh();
            $this->dispatch('success', 'HTTP origin through Cloudflare Tunnel is enabled. New domains default to HTTP with Redirect HTTP to HTTPS off.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function disableHttpOrigin(): void
    {
        try {
            $this->authorize('update', $this->server);
            $this->server->settings->is_cloudflare_http_tunnel = false;
            $this->server->settings->save();
            $this->refresh();
            $this->dispatch('success', 'Cloudflare Tunnel HTTP origin disabled. Direct mode is restored for new domains.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.cloudflare-tunnel');
    }

    private function hydrateHttpOrigin(): void
    {
        $settings = $this->server->settings;
        $this->isCloudflareHttpTunnelEnabled = (bool) $settings->is_cloudflare_http_tunnel;
        $this->httpCname = (string) ($settings->cloudflare_http_tunnel_cname ?? '');
    }

    private function normalizedHttpCname(): ?string
    {
        $value = trim($this->httpCname);
        if ($value === '') {
            return null;
        }

        foreach (['https://', 'http://'] as $prefix) {
            if (str_starts_with(strtolower($value), $prefix)) {
                $host = parse_url($value, PHP_URL_HOST);

                return is_string($host) && $host !== '' ? $host : $value;
            }
        }

        return $value;
    }
}
