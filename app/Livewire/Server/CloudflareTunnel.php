<?php

namespace App\Livewire\Server;

use App\Actions\Server\ConfigureCloudflared;
use App\Actions\Server\UpdateCloudflared;
use App\Exceptions\DnsRecordConflictException;
use App\Models\IntegrationToken;
use App\Models\Server;
use App\Rules\ValidHostname;
use App\Services\CloudflareTunnelService;
use App\Traits\ListensToTeamChannel;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
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

    public ?int $cloudflare_integration_token_id = null;

    public string $api_ssh_domain = '';

    /**
     * An existing DNS record on the hostname that blocks the tunnel CNAME, waiting for the user to confirm its replacement.
     *
     * @var array{record_id: string, current: string, hostname: string, token_id: int}|null
     */
    #[Locked]
    public ?array $dnsConflict = null;

    public function getListeners()
    {
        return $this->teamChannelListeners([
            'CloudflareTunnelConfigured' => 'refresh',
        ]);
    }

    /**
     * Selects the token that the user just added from this page, when it is the only one that can manage tunnels.
     */
    #[On('integrationTokenAdded')]
    public function selectAddedToken(): void
    {
        $tunnelTokens = $this->tunnelTokens();
        if ($this->cloudflare_integration_token_id === null && $tunnelTokens->count() === 1) {
            $this->cloudflare_integration_token_id = $tunnelTokens->first()->id;
        }
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

    public function toggleCloudflareTunnels(CloudflareTunnelService $tunnels)
    {
        try {
            $this->authorize('update', $this->server);
            $this->server->assertNotTransferredAway();
            if (filled($this->server->settings->cloudflare_tunnel_id)) {
                foreach ($tunnels->tearDown($this->server) as $warning) {
                    $this->dispatch('warning', $warning);
                }
            } else {
                remote_process(['docker rm -f coolify-cloudflared'], $this->server, false, 10);
            }
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
            $this->server->assertNotTransferredAway();
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
            $this->server->assertNotTransferredAway();
            $this->ssh_domain = $this->normalizeSshDomain($this->ssh_domain);
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

    public function apiCloudflareConfig(CloudflareTunnelService $tunnels): void
    {
        $this->authorize('update', $this->server);
        $this->api_ssh_domain = $this->normalizeSshDomain($this->api_ssh_domain);
        $this->validate([
            'cloudflare_integration_token_id' => ['required', 'integer'],
            'api_ssh_domain' => ['required', 'string', new ValidHostname],
        ], [], ['cloudflare_integration_token_id' => 'Cloudflare token', 'api_ssh_domain' => 'SSH hostname']);

        $token = $this->tunnelTokens()->firstWhere('id', $this->cloudflare_integration_token_id);
        if ($token === null) {
            $this->addError('cloudflare_integration_token_id', 'Select a Cloudflare token of this server team with the Cloudflare Tunnel capability.');

            return;
        }

        $this->dnsConflict = null;
        $this->startApiSetup($tunnels, $token, $this->api_ssh_domain);
    }

    public function replaceDnsAndConfigure(CloudflareTunnelService $tunnels): void
    {
        $this->authorize('update', $this->server);
        $conflict = $this->dnsConflict;
        $token = $conflict === null ? null : $this->tunnelTokens()->firstWhere('id', $conflict['token_id']);
        if ($conflict === null || $token === null) {
            $this->dnsConflict = null;
            $this->dispatch('error', 'The DNS conflict is no longer available. Set up the tunnel again.');

            return;
        }

        $this->dnsConflict = null;
        $this->startApiSetup($tunnels, $token, $conflict['hostname'], $conflict);
    }

    public function render()
    {
        $cloudflareTokens = IntegrationToken::query()
            ->where('team_id', $this->server->team_id)
            ->where('provider', 'cloudflare')
            ->count();

        return view('livewire.server.cloudflare-tunnel', [
            'tunnelTokens' => $this->tunnelTokens(),
            'hasCloudflareTokens' => $cloudflareTokens > 0,
        ]);
    }

    /**
     * Cloudflare tokens of the server's team (not the session team) that can manage tunnels.
     *
     * @return Collection<int, IntegrationToken>
     */
    private function tunnelTokens(): Collection
    {
        return IntegrationToken::query()
            ->where('team_id', $this->server->team_id)
            ->where('provider', 'cloudflare')
            ->orderBy('name')
            ->get()
            ->filter(fn (IntegrationToken $token): bool => $token->hasCapability('tunnel'))
            ->values();
    }

    /**
     * @param  array{record_id: string, current: string}|null  $confirmedConflict
     */
    private function startApiSetup(CloudflareTunnelService $tunnels, IntegrationToken $token, string $hostname, ?array $confirmedConflict = null): void
    {
        try {
            $activity = $tunnels->setUp($this->server, $token, $hostname, $confirmedConflict);
            auditLog('ui.server.cloudflare_tunnel.api_configuration_started', $this->auditContext([
                'ssh_domain' => $hostname,
                'integration_token_uuid' => $token->uuid,
            ]));
            $this->dispatch('automated');
            $this->dispatch('activityMonitor', $activity->id);
        } catch (DnsRecordConflictException $e) {
            $this->dnsConflict = [
                'record_id' => $e->providerRecordId,
                'current' => $e->currentValue,
                'hostname' => $hostname,
                'token_id' => $token->id,
            ];
        } catch (LockTimeoutException) {
            $this->dispatch('error', "Another DNS change for {$hostname} is in progress. Try again in a few seconds.");
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    private function normalizeSshDomain(string $domain): string
    {
        return str($domain)->replace(['https://', 'http://'], '')->replace('/', '')->trim()->lower()->value();
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
