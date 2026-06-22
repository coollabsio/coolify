<?php

namespace App\Services\Flux;

use App\Models\V5\Server as V5Server;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\File;
use RuntimeException;

class AgentTokenIssuer
{
    public const DEFAULT_PROFILE = 'host-agent:default';

    /**
     * @param  array<int, string>  $capabilities
     * @param  array<string, mixed>  $extraClaims
     */
    public function issue(string $hostId, array $capabilities = [self::DEFAULT_PROFILE], int $ttl = 86400, array $extraClaims = []): string
    {
        if ($hostId === '') {
            throw new RuntimeException('Flux host id is required.');
        }

        $privateKeyPath = config('flux.jwt_private_key_path');

        if (! is_string($privateKeyPath) || $privateKeyPath === '' || ! File::isReadable($privateKeyPath)) {
            throw new RuntimeException("Flux JWT private key not found at {$privateKeyPath}.");
        }

        $now = time();

        return JWT::encode(array_merge($extraClaims, [
            'sub' => $hostId,
            'aud' => 'coold',
            'caps' => $this->normalizeCapabilities($capabilities),
            'iat' => $now,
            'exp' => $now + max(60, $ttl),
        ]), File::get($privateKeyPath), 'ES256');
    }

    public function issueForServer(V5Server $server, int $ttl = 86400): string
    {
        $hostId = $server->wireguard_management_ip ?: $server->node_address;

        if (! is_string($hostId) || $hostId === '') {
            throw new RuntimeException('Server is missing its Flux host id.');
        }

        return $this->issue($hostId, [self::DEFAULT_PROFILE], $ttl, [
            'team_id' => $server->team_id,
            'cluster_id' => $server->cluster_id,
            'server_id' => $server->id,
        ]);
    }

    /**
     * @param  array<int, string>  $capabilities
     * @return array<int, string>
     */
    private function normalizeCapabilities(array $capabilities): array
    {
        $normalized = collect($capabilities)
            ->map(fn (string $capability) => trim($capability))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($normalized === []) {
            return [self::DEFAULT_PROFILE];
        }

        return $normalized;
    }
}
