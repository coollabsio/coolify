<?php

namespace App\Actions\Sentinel;

use App\Models\Server;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Illuminate\Support\Str;
use RuntimeException;

class IssueFluxCredential
{
    /**
     * @param  list<string>  $capabilities
     * @return array{credential: string, expires_at: CarbonImmutable, capabilities: list<string>}
     */
    public function issue(Server $server, array $capabilities, int $protocolMin, int $protocolMax): array
    {
        if (! $server->isNode()) {
            throw new RuntimeException('Flux is available only for nodes.');
        }

        $privateKey = config('constants.flux.signing_private_key');
        $keyId = config('constants.flux.signing_key_id');
        $issuer = config('constants.flux.issuer');
        if (! is_string($privateKey) || $privateKey === '' || ! is_string($keyId) || $keyId === '' || ! is_string($issuer) || $issuer === '') {
            throw new RuntimeException('Flux signing configuration is incomplete.');
        }

        $now = CarbonImmutable::now();
        $expiresAt = $now->addMinutes(15);
        $grantedCapabilities = array_values(array_intersect([
            'system.ping.v1',
            'system.info.v1',
        ], $capabilities));

        $credential = JWT::encode([
            'iss' => $issuer,
            'aud' => 'flux',
            'purpose' => 'node-control-channel',
            'sub' => $server->uuid,
            'jti' => (string) Str::uuid(),
            'iat' => $now->timestamp,
            'nbf' => $now->subSeconds(5)->timestamp,
            'exp' => $expiresAt->timestamp,
            'caps' => $grantedCapabilities,
            'pmin' => max(1, $protocolMin),
            'pmax' => min(1, $protocolMax),
        ], $privateKey, 'EdDSA', $keyId);

        return [
            'credential' => $credential,
            'expires_at' => $expiresAt,
            'capabilities' => $grantedCapabilities,
        ];
    }
}
