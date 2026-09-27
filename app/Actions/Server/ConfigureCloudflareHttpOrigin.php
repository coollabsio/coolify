<?php

namespace App\Actions\Server;

use App\Models\IntegrationToken;
use App\Models\Server;
use App\Services\Cloudflare\CloudflareTunnelApi;
use App\Support\CloudflareHttpTunnel;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;

class ConfigureCloudflareHttpOrigin
{
    use AsAction;

    public function handle(
        Server $server,
        string $apiToken,
        string $accountId,
        ?string $tunnelId,
        string $hostname,
        bool $installConnector,
        bool $sshAlreadyEnabled,
        ?string $dashboardHostname = null,
        ?string $zoneId = null,
    ): ?Activity {
        $api = app(CloudflareTunnelApi::class);
        $hostname = $this->normalizeHostname($hostname) ?? '';

        if ($hostname === '') {
            throw new RuntimeException('A hostname or wildcard is required for HTTP origin.');
        }

        if (blank($tunnelId)) {
            $created = $api->createTunnel($apiToken, $accountId, 'coolify-'.$server->uuid);
            $tunnelId = $created['id'];
        }

        $cname = $tunnelId.'.cfargotunnel.com';
        $ingress = $this->ingressRules($hostname, $dashboardHostname);

        $api->putConfiguration($apiToken, $accountId, $tunnelId, $ingress);
        $connectorToken = $api->tunnelToken($apiToken, $accountId, $tunnelId);

        $integrationTokenId = $this->persistApiToken($server, $apiToken);

        if (filled($zoneId)) {
            $this->ensureDnsCnames($api, $apiToken, $zoneId, $cname, $hostname, $dashboardHostname);
        }

        $server->settings->cloudflare_http_tunnel_id = $tunnelId;
        $server->settings->cloudflare_http_tunnel_cname = $cname;
        $server->settings->cloudflare_http_tunnel_account_id = $accountId;
        $server->settings->cloudflare_http_tunnel_zone_id = $zoneId;
        $server->settings->cloudflare_http_tunnel_hostname = $hostname;
        $server->settings->cloudflare_http_tunnel_token = $connectorToken;
        $server->settings->cloudflare_http_tunnel_integration_token_id = $integrationTokenId;
        $server->settings->cloudflare_dashboard_hostname = filled($dashboardHostname) ? $this->normalizeHostname($dashboardHostname) : null;
        $server->settings->cloudflare_http_tunnel_use_existing_connector = ! $installConnector;
        $server->settings->is_cloudflare_http_tunnel = true;
        $server->settings->save();

        if (! $installConnector) {
            return null;
        }

        $containerName = $sshAlreadyEnabled ? 'coolify-http-cloudflared' : 'coolify-cloudflared';

        return ConfigureCloudflared::run(
            $server,
            $connectorToken,
            '',
            true,
            $sshAlreadyEnabled,
            $containerName,
        );
    }

    /**
     * @return array<int, array{hostname?: string, service: string}>
     */
    public function ingressRules(string $hostname, ?string $dashboardHostname = null): array
    {
        $rules = [];
        $dashboard = $this->normalizeHostname($dashboardHostname);
        if (filled($dashboard)) {
            $rules[] = [
                'hostname' => $dashboard,
                'service' => 'http://127.0.0.1:'.(int) config('app.port', 8000),
            ];
        }

        $rules[] = [
            'hostname' => $hostname,
            'service' => CloudflareHttpTunnel::ORIGIN_SERVICE,
        ];

        $apex = $this->apexFromHostname($hostname);
        if ($apex !== null) {
            $rules[] = [
                'hostname' => $apex,
                'service' => CloudflareHttpTunnel::ORIGIN_SERVICE,
            ];
        }

        $rules[] = [
            'service' => CloudflareHttpTunnel::CATCH_ALL_SERVICE,
        ];

        return $rules;
    }

    /**
     * @return array<int, string>
     */
    public function hostnamesToPublish(string $hostname, ?string $dashboardHostname = null): array
    {
        $names = collect([$this->normalizeHostname($hostname), $this->apexFromHostname($hostname), $this->normalizeHostname($dashboardHostname)])
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $names;
    }

    public function normalizeHostname(?string $hostname): ?string
    {
        if (! is_string($hostname) || trim($hostname) === '') {
            return null;
        }

        $hostname = strtolower(trim($hostname));
        $hostname = preg_replace('#^https?://#', '', $hostname) ?? $hostname;
        $hostname = explode('/', $hostname)[0] ?? $hostname;

        return rtrim($hostname, '.') ?: null;
    }

    public function apexFromHostname(string $hostname): ?string
    {
        if (! str_starts_with($hostname, '*.')) {
            return null;
        }

        $apex = substr($hostname, 2);

        return $apex !== '' ? $apex : null;
    }

    private function persistApiToken(Server $server, string $apiToken): int
    {
        $existing = $server->settings->cloudflareHttpTunnelIntegrationToken;
        if ($existing) {
            $existing->update(['token' => $apiToken]);

            return $existing->id;
        }

        $token = IntegrationToken::query()->create([
            'team_id' => $server->team_id,
            'provider' => 'cloudflare',
            'name' => 'Cloudflare Tunnel ('.$server->name.')',
            'token' => $apiToken,
            'capabilities' => ['dns', 'tunnel'],
            'metadata' => ['automatic_dns' => true, 'server_uuid' => $server->uuid],
        ]);

        return $token->id;
    }

    private function ensureDnsCnames(
        CloudflareTunnelApi $api,
        string $apiToken,
        string $zoneId,
        string $cname,
        string $hostname,
        ?string $dashboardHostname,
    ): void {
        foreach ($this->hostnamesToPublish($hostname, $dashboardHostname) as $name) {
            $api->createProxiedCnameIfMissing($apiToken, $zoneId, $name, $cname);
        }
    }
}
