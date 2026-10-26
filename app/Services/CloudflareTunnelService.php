<?php

namespace App\Services;

use App\Actions\Server\ConfigureCloudflared;
use App\Enums\ManagedDnsDeletionResult;
use App\Exceptions\DnsRecordConflictException;
use App\Models\DnsProviderZone;
use App\Models\IntegrationToken;
use App\Models\ManagedDnsRecord;
use App\Models\Server;
use App\Services\Dns\CloudflareDnsProvider;
use App\Services\Dns\ManagedDnsRecordCleanup;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * Sets up and removes a remotely managed Cloudflare Tunnel for server SSH with a stored Cloudflare API token.
 * The tunnel token is never stored: Cloudflare returns it on every setup run.
 */
class CloudflareTunnelService
{
    private const API_URL = 'https://api.cloudflare.com/client/v4';

    private const TUNNEL_PERMISSION = 'Account → Cloudflare Tunnel → Edit';

    /** Cloudflare does not delete a tunnel while the stopping connector is still connected; wait at most this often. */
    private const DELETE_ATTEMPTS = 5;

    public function __construct(
        private CloudflareDnsProvider $dns,
        private ManagedDnsRecordCleanup $cleanup,
    ) {}

    public static function tunnelName(Server $server): string
    {
        return "coolify-{$server->uuid}";
    }

    /**
     * Creates (or reuses) the tunnel, routes the hostname to the server SSH port, points a proxied CNAME at the tunnel,
     * and starts cloudflared. The CloudflareTunnelChanged listener enables the tunnel when cloudflared is healthy.
     *
     * @param  array{record_id: string, current: string}|null  $confirmedConflict  An existing DNS record the user agreed to replace.
     *
     * @throws DnsRecordConflictException when another DNS record already uses the hostname
     * @throws LockTimeoutException when another DNS change for the hostname does not finish in time
     * @throws RuntimeException when a Cloudflare call fails
     */
    public function setUp(Server $server, IntegrationToken $token, string $hostname, ?array $confirmedConflict = null): Activity
    {
        $server->assertNotTransferredAway();
        $zone = $this->zoneFor($server, $token, $hostname);
        $accountId = $zone->account_id;
        if (blank($accountId)) {
            throw new RuntimeException("Cloudflare returned no account for the zone {$zone->name}.");
        }

        [$tunnelId, $tunnelToken, $created] = $this->findOrCreateTunnel($token, $accountId, self::tunnelName($server));

        try {
            $this->request($token, 'put', "/accounts/{$accountId}/cfd_tunnel/{$tunnelId}/configurations", [
                'config' => ['ingress' => [
                    ['hostname' => $hostname, 'service' => "ssh://localhost:{$server->port}"],
                    ['service' => 'http_status:404'],
                ]],
            ]);

            $target = "{$tunnelId}.cfargotunnel.com";
            if ($confirmedConflict !== null) {
                $this->dns->replaceRecord($zone, $confirmedConflict['record_id'], $hostname, $target, $server, $confirmedConflict['current'], proxied: true);
            } else {
                $this->dns->createRecord($zone, $hostname, $target, $server, proxied: true);
            }
        } catch (Throwable $e) {
            // Coolify does not track the tunnel yet, so a tunnel created by this run would stay in Cloudflare.
            if ($created) {
                $this->deleteUntrackedTunnel($token, $accountId, $tunnelId);
            }

            throw $e;
        }
        $this->releaseDnsRecords($server, except: $hostname);

        // Saved before cloudflared starts, so disabling the tunnel can clean up even when cloudflared does not become healthy.
        $server->settings->update([
            'cloudflare_tunnel_id' => $tunnelId,
            'cloudflare_tunnel_account_id' => $accountId,
            'cloudflare_tunnel_integration_token_id' => $token->id,
        ]);

        return ConfigureCloudflared::run($server, $tunnelToken, $hostname);
    }

    /**
     * Stops cloudflared and deletes the tunnel and the DNS record that setUp() created.
     * Never throws; returns warnings for the user and always forgets the tunnel.
     *
     * @return array<int, string>
     */
    public function tearDown(Server $server): array
    {
        $settings = $server->settings;
        if (blank($settings->cloudflare_tunnel_id)) {
            return [];
        }
        // Another Coolify instance uses the tunnel of a transferred server.
        if ($server->isTransferredAway()) {
            return ['This server is managed by another Coolify instance.'];
        }

        $warnings = [];
        $name = self::tunnelName($server);
        $token = IntegrationToken::query()
            ->where('team_id', $server->team_id)
            ->whereKey($settings->cloudflare_tunnel_integration_token_id)
            ->first();

        // Coolify usually reaches the server through this tunnel. Stopping cloudflared in the SSH session would cut
        // that session and make SSH retry, so the stop runs detached and the session ends first.
        instant_remote_process([
            "nohup setsid sh -c 'sleep 2; docker rm -f coolify-cloudflared' > /dev/null 2>&1 < /dev/null &",
        ], $server, false);

        // The DNS record does not depend on the connector, so it goes first while cloudflared stops.
        array_push($warnings, ...$this->releaseDnsRecords($server));

        if ($token === null) {
            $warnings[] = "The Cloudflare token of the tunnel no longer exists. Delete the tunnel {$name} in Cloudflare.";
        } elseif (($warning = $this->deleteStoppedTunnel($token, $settings->cloudflare_tunnel_account_id, $settings->cloudflare_tunnel_id, $name)) !== null) {
            $warnings[] = $warning;
        }

        $settings->update([
            'cloudflare_tunnel_id' => null,
            'cloudflare_tunnel_account_id' => null,
            'cloudflare_tunnel_integration_token_id' => null,
        ]);

        return $warnings;
    }

    /**
     * Deletes the tunnel once its connector is gone. Removing the connections first lets Cloudflare forget a connector
     * that already stopped; a connector that is still stopping keeps the delete failing until it disconnects.
     */
    private function deleteStoppedTunnel(IntegrationToken $token, string $accountId, string $tunnelId, string $name): ?string
    {
        $path = self::API_URL."/accounts/{$accountId}/cfd_tunnel/{$tunnelId}";

        try {
            for ($attempt = 1; $attempt <= self::DELETE_ATTEMPTS; $attempt++) {
                $this->client($token)->delete("{$path}/connections");
                $response = $this->client($token)->delete($path);
                if ($response->successful() || $response->status() === 404) {
                    return null;
                }
                if ($response->status() === 403) {
                    return CloudflareDnsProvider::missingPermissionMessage(self::TUNNEL_PERMISSION)." Delete the tunnel {$name} in Cloudflare.";
                }
                if ($attempt < self::DELETE_ATTEMPTS) {
                    Sleep::for(2)->seconds();
                }
            }
        } catch (Throwable) {
            return "Cloudflare did not respond. Delete the tunnel {$name} in Cloudflare.";
        }

        return "Cloudflare could not delete the tunnel {$name}. Delete it in Cloudflare.";
    }

    private function zoneFor(Server $server, IntegrationToken $token, string $hostname): DnsProviderZone
    {
        $find = fn (): ?DnsProviderZone => $this->dns->findZones($server->team_id, $hostname)
            ->firstWhere('integration_token_id', $token->id);

        $zone = $find();
        if ($zone === null) {
            $this->dns->syncZones($token);
            $zone = $find();
        }

        return $zone ?? throw new RuntimeException("No Cloudflare zone of the token {$token->name} manages {$hostname}.");
    }

    /**
     * @return array{0: string, 1: string, 2: bool} tunnel ID, tunnel token, and whether this call created the tunnel
     */
    private function findOrCreateTunnel(IntegrationToken $token, string $accountId, string $name): array
    {
        $existing = collect($this->request($token, 'get', "/accounts/{$accountId}/cfd_tunnel", [
            'name' => $name,
            'is_deleted' => 'false',
        ])->json('result', []))->first(fn ($tunnel): bool => is_array($tunnel) && ($tunnel['name'] ?? null) === $name);

        if (is_string($existing['id'] ?? null)) {
            $tunnelId = $existing['id'];
            $tunnelToken = $this->request($token, 'get', "/accounts/{$accountId}/cfd_tunnel/{$tunnelId}/token")->json('result');
            $created = false;
        } else {
            $response = $this->request($token, 'post', "/accounts/{$accountId}/cfd_tunnel", [
                'name' => $name,
                'config_src' => 'cloudflare',
            ]);
            $tunnelId = $response->json('result.id');
            $tunnelToken = $response->json('result.token');
            $created = true;
        }

        if (! is_string($tunnelId) || ! is_string($tunnelToken) || $tunnelToken === '') {
            throw new RuntimeException('Cloudflare returned no tunnel token.');
        }

        return [$tunnelId, $tunnelToken, $created];
    }

    /**
     * Best effort: the tunnel has no connector yet, so Cloudflare deletes it directly.
     */
    private function deleteUntrackedTunnel(IntegrationToken $token, string $accountId, string $tunnelId): void
    {
        try {
            $this->client($token)->delete(self::API_URL."/accounts/{$accountId}/cfd_tunnel/{$tunnelId}");
        } catch (Throwable) {
            // The original error matters more; a re-run reuses the tunnel by name anyway.
        }
    }

    /**
     * Releases the server's managed DNS records (except the given hostname). Records are deleted only when
     * Coolify created them and nothing else uses the hostname.
     *
     * @return array<int, string>
     */
    private function releaseDnsRecords(Server $server, ?string $except = null): array
    {
        $key = [$server->getMorphClass(), $server->getKey()];
        $records = ManagedDnsRecord::query()
            ->where('team_id', $server->team_id)
            ->when($except !== null, fn ($query) => $query->where('name', '!=', $except))
            ->whereHas('references', fn ($query) => $query->where('resource_type', $key[0])->where('resource_id', $key[1]))
            ->get();

        return $records->map(function (ManagedDnsRecord $record) use ($key): ?string {
            try {
                $result = $this->cleanup->release($record, [$key], true);
            } catch (Throwable) {
                $result = ManagedDnsDeletionResult::Failed;
            }

            return match ($result) {
                ManagedDnsDeletionResult::ChangedExternally => "The DNS record {$record->name} changed outside Coolify and was left untouched.",
                ManagedDnsDeletionResult::Failed, ManagedDnsDeletionResult::Busy => "The DNS record {$record->name} could not be deleted. Delete it in Cloudflare.",
                default => null,
            };
        })->filter()->values()->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function request(IntegrationToken $token, string $method, string $path, array $data = []): Response
    {
        $response = $this->client($token)->{$method}(self::API_URL.$path, $data);

        if ($response->status() === 403) {
            throw new RuntimeException(CloudflareDnsProvider::missingPermissionMessage(self::TUNNEL_PERMISSION));
        }
        if (! $response->successful() || $response->json('success') === false) {
            $message = $response->json('errors.0.message');

            throw new RuntimeException('Cloudflare Tunnel request failed'.(is_string($message) ? ": {$message}" : '.'));
        }

        return $response;
    }

    private function client(IntegrationToken $token): PendingRequest
    {
        return Http::withToken($token->token)->acceptJson()->connectTimeout(5)->timeout(15);
    }
}
