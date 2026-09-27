<?php

namespace App\Livewire\Server;

use App\Actions\Server\ConfigureCloudflared;
use App\Actions\Server\ConfigureCloudflareHttpOrigin;
use App\Models\Server;
use App\Services\Cloudflare\CloudflareTunnelApi;
use App\Support\CloudflareHttpTunnel;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use RuntimeException;

class CloudflareTunnel extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public string $cloudflare_token = '';

    public string $ssh_domain = '';

    public bool $isCloudflareTunnelsEnabled = false;

    public bool $isCloudflareHttpTunnelEnabled = false;

    public string $httpApiToken = '';

    public string $httpAccountId = '';

    public string $httpZoneId = '';

    public string $httpTunnelId = '';

    public string $httpHostname = '';

    public string $httpDashboardHostname = '';

    public bool $installHttpConnector = true;

    public bool $createMissingDnsRecords = true;

    /** @var array<int, array{id: string, name: string}> */
    public array $httpAccounts = [];

    /** @var array<int, array{id: string, name: string, account_id: string}> */
    public array $httpZones = [];

    /** @var array<int, array{id: string, name: string, status: string, cname: string}> */
    public array $httpTunnels = [];

    public bool $existingConnectorDetected = false;

    public bool $proxyRunning = false;

    public ?string $httpCname = null;

    public ?string $httpLastSeenAt = null;

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
        $this->hydrateHttpState();
    }

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->isCloudflareTunnelsEnabled = $this->server->settings->is_cloudflare_tunnel;
            $this->hydrateHttpState();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function toggleCloudflareTunnels()
    {
        try {
            $this->authorize('update', $this->server);
            if (! $this->server->isCloudflareHttpTunnel()) {
                remote_process(['docker rm -f coolify-cloudflared'], $this->server, false, 10);
            }
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
            $this->validate([
                'cloudflare_token' => ['required', 'string'],
                'ssh_domain' => ['required', 'string'],
            ]);
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

    public function loadCloudflareAccounts(): void
    {
        try {
            $this->authorize('update', $this->server);
            $token = $this->httpApiToken();
            $api = app(CloudflareTunnelApi::class);
            $this->httpAccounts = $api->listAccounts($token);
            $this->httpZones = $api->listZones($token);
            if ($this->httpAccountId === '' && count($this->httpAccounts) === 1) {
                $this->httpAccountId = $this->httpAccounts[0]['id'];
            }
            if ($this->httpAccountId !== '') {
                $this->httpTunnels = $api->listTunnels($token, $this->httpAccountId);
            }
            $this->dispatch('success', 'Cloudflare account and zone list loaded.');
        } catch (\Throwable $e) {
            $this->httpAccounts = [];
            $this->httpZones = [];
            $this->httpTunnels = [];
            $this->dispatch('error', $this->safeCloudflareError($e));
        }
    }

    public function updatedHttpAccountId(): void
    {
        if ($this->httpAccountId === '' || $this->httpApiToken() === '') {
            $this->httpTunnels = [];

            return;
        }

        try {
            $this->authorize('update', $this->server);
            $this->httpTunnels = app(CloudflareTunnelApi::class)->listTunnels($this->httpApiToken(), $this->httpAccountId);
        } catch (\Throwable $e) {
            $this->httpTunnels = [];
            $this->dispatch('error', $this->safeCloudflareError($e));
        }
    }

    public function enableHttpOrigin(): void
    {
        try {
            $this->authorize('update', $this->server);
            $this->validate([
                'httpAccountId' => ['required', 'string'],
                'httpHostname' => ['required', 'string'],
            ]);

            if (! $this->proxyRunning) {
                $this->dispatch('error', 'Start the Coolify proxy on this server before enabling Cloudflare Tunnel HTTP origin. The tunnel forwards to http://127.0.0.1:80.');

                return;
            }

            $activity = ConfigureCloudflareHttpOrigin::run(
                $this->server,
                $this->httpApiToken(),
                $this->httpAccountId,
                filled($this->httpTunnelId) ? $this->httpTunnelId : null,
                $this->httpHostname,
                $this->installHttpConnector,
                $this->server->settings->is_cloudflare_tunnel,
                filled($this->httpDashboardHostname) ? $this->httpDashboardHostname : null,
                $this->createMissingDnsRecords && filled($this->httpZoneId) ? $this->httpZoneId : null,
            );

            $this->httpApiToken = '';
            $this->refresh();

            if ($activity) {
                $this->dispatch('http-origin-started');
                $this->dispatch('activityMonitor', $activity->id);
            } else {
                $this->dispatch('success', 'Cloudflare Tunnel HTTP origin is configured. Coolify domains should stay HTTP with Redirect HTTP to HTTPS disabled.');
            }
        } catch (\Throwable $e) {
            $this->dispatch('error', $this->safeCloudflareError($e));
        }
    }

    public function markHttpOriginManually(): void
    {
        try {
            $this->authorize('update', $this->server);
            $this->server->settings->is_cloudflare_http_tunnel = true;
            if (filled($this->httpHostname)) {
                $this->server->settings->cloudflare_http_tunnel_hostname = $this->httpHostname;
            }
            if (filled($this->httpTunnelId)) {
                $this->server->settings->cloudflare_http_tunnel_id = $this->httpTunnelId;
                $this->server->settings->cloudflare_http_tunnel_cname = $this->httpTunnelId.'.cfargotunnel.com';
            }
            $this->server->settings->cloudflare_http_tunnel_use_existing_connector = true;
            $this->server->settings->save();
            $this->refresh();
            $this->dispatch('success', 'HTTP origin through Cloudflare Tunnel is marked as enabled. New domains default to HTTP with Redirect HTTP to HTTPS off.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function disableHttpOrigin(): void
    {
        try {
            $this->authorize('update', $this->server);
            $settings = $this->server->settings;
            if (! $settings->cloudflare_http_tunnel_use_existing_connector) {
                $container = $settings->is_cloudflare_tunnel ? 'coolify-http-cloudflared' : 'coolify-cloudflared';
                remote_process(["docker rm -f {$container} || true"], $this->server, false, false, 10);
            }
            $settings->is_cloudflare_http_tunnel = false;
            $settings->cloudflare_http_tunnel_hostname = null;
            $settings->cloudflare_dashboard_hostname = null;
            $settings->cloudflare_http_tunnel_last_seen_at = null;
            $settings->save();
            $this->refresh();
            $this->dispatch('success', 'Cloudflare Tunnel HTTP origin disabled. Direct mode (A records and Let’s Encrypt) is restored for new domains.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.cloudflare-tunnel', [
            'cnameTarget' => $this->httpCname ?: '<tunnel-id>'.CloudflareHttpTunnel::CFARGO_SUFFIX,
        ]);
    }

    private function hydrateHttpState(): void
    {
        $settings = $this->server->settings;
        $this->isCloudflareHttpTunnelEnabled = (bool) $settings->is_cloudflare_http_tunnel;
        $this->httpCname = CloudflareHttpTunnel::fromServer($this->server);
        $this->httpLastSeenAt = $settings->cloudflare_http_tunnel_last_seen_at?->toIso8601String();
        $this->httpAccountId = (string) ($settings->cloudflare_http_tunnel_account_id ?? $this->httpAccountId);
        $this->httpZoneId = (string) ($settings->cloudflare_http_tunnel_zone_id ?? $this->httpZoneId);
        $this->httpTunnelId = (string) ($settings->cloudflare_http_tunnel_id ?? $this->httpTunnelId);
        $this->httpHostname = (string) ($settings->cloudflare_http_tunnel_hostname ?? $this->httpHostname);
        $this->httpDashboardHostname = (string) ($settings->cloudflare_dashboard_hostname ?? $this->httpDashboardHostname);
        $this->installHttpConnector = ! $settings->cloudflare_http_tunnel_use_existing_connector;
        $this->proxyRunning = ($this->server->proxy->status ?? 'unknown') === 'running';
        $this->existingConnectorDetected = $this->detectExistingConnector();
        if ($this->existingConnectorDetected && ! $this->isCloudflareHttpTunnelEnabled) {
            $this->installHttpConnector = false;
        }
    }

    private function detectExistingConnector(): bool
    {
        if (! $this->server->isFunctional()) {
            return false;
        }

        try {
            $names = instant_remote_process(
                ['docker ps --format "{{.Names}}"'],
                $this->server,
                false,
                false,
                8,
            );
        } catch (\Throwable) {
            return false;
        }

        if (! is_string($names) || $names === '') {
            return false;
        }

        return str($names)->contains(['coolify-cloudflared', 'coolify-http-cloudflared', 'cloudflare-tunnel']);
    }

    private function httpApiToken(): string
    {
        $token = trim($this->httpApiToken);
        if ($token !== '') {
            return $token;
        }

        $stored = $this->server->settings->cloudflareHttpTunnelIntegrationToken?->token;
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        throw new RuntimeException('Paste a Cloudflare API token with Tunnel Edit, Zone DNS Edit, and Zone Read.');
    }

    private function safeCloudflareError(\Throwable $e): string
    {
        $message = $e->getMessage();
        $redacted = CloudflareHttpTunnel::redactSecrets($message);
        Log::warning('Cloudflare Tunnel HTTP origin failed', ['server_id' => $this->server->id]);

        return $redacted;
    }
}
