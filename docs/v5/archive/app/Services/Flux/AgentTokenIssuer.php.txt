<?php

namespace App\Services\Flux;

use App\Models\V5\RevokedAgentToken;
use App\Models\V5\Server as V5Server;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Mints the per-host ES256 JWT that authorizes a coold host agent against flux.
 *
 * Capability scoping: by default the token carries the EXPLICIT list of
 * primitive capability strings coold advertises (config('flux.host_capabilities'),
 * mirroring coold/coold/src/grpc/client.rs:204-231) rather than the
 * `host-agent:default` wildcard profile. flux intersects the jwt `caps` with
 * coold's advertised set (flux/src/main.rs:128-141), so the effective power is
 * unchanged, but the token no longer depends on flux's
 * `capability_profile_authorizes_all` wildcard bypass (main.rs:124-126).
 *
 * @see config/flux.php for the capability list, escape hatch, TTL and kid config.
 */
class AgentTokenIssuer
{
    public const DEFAULT_PROFILE = 'host-agent:default';

    private const TTL_FLOOR_SECONDS = 60;

    /**
     * Mint a host JWT.
     *
     * @param  array<int, string>|null  $capabilities  Explicit caps; null resolves the configured default set (or escape-hatch profile).
     * @param  int|null  $ttl  Lifetime in seconds; null resolves config('flux.host_token_ttl'). Clamped to a 60s floor.
     * @param  array<string, mixed>  $extraClaims  Extra claims merged in (a `jti` here is honored, otherwise one is generated).
     */
    public function issue(string $hostId, ?array $capabilities = null, ?int $ttl = null, array $extraClaims = []): string
    {
        if ($hostId === '') {
            throw new RuntimeException('Flux host id is required.');
        }

        $privateKeyPath = config('flux.jwt_private_key_path');

        if (! is_string($privateKeyPath) || $privateKeyPath === '' || ! File::isReadable($privateKeyPath)) {
            throw new RuntimeException("Flux JWT private key not found at {$privateKeyPath}.");
        }

        $this->assertPrivateKeyPermissions($privateKeyPath);

        $capabilities ??= $this->defaultCapabilities();
        $ttl ??= (int) config('flux.host_token_ttl', 3600);

        $jti = $extraClaims['jti'] ?? (string) Str::uuid();
        unset($extraClaims['jti']);

        $now = time();
        $keyId = (string) config('flux.jwt_kid', 'flux-default');

        return JWT::encode(array_merge($extraClaims, [
            'sub' => $hostId,
            'aud' => 'coold',
            'caps' => $this->normalizeCapabilities($capabilities),
            'jti' => $jti,
            'iat' => $now,
            'exp' => $now + max(self::TTL_FLOOR_SECONDS, $ttl),
        ]), File::get($privateKeyPath), 'ES256', $keyId !== '' ? $keyId : null);
    }

    public function issueForServer(V5Server $server, ?int $ttl = null): string
    {
        $hostId = $server->fluxHostId();

        if ($hostId === '') {
            throw new RuntimeException('Server is missing a valid Flux host id.');
        }

        $jti = (string) Str::uuid();
        $ttl ??= (int) config('flux.host_token_ttl', 3600);

        // team_id/cluster_id/server_id are minted as STRINGS: flux deserializes
        // the `team_id` claim as a string (coold/flux/src/auth.rs Claims), and
        // rejects the whole token with a JSON type error if it arrives as a JSON
        // integer. Keep the sibling ids string-typed for consistency.
        $token = $this->issue($hostId, $this->defaultCapabilities(), $ttl, [
            'jti' => $jti,
            'team_id' => (string) $server->team_id,
            'cluster_id' => (string) $server->cluster_id,
            'server_id' => $hostId,
            'wireguard_management_ip' => (string) $server->wireguard_management_ip,
        ]);

        // Persist the freshly issued jti (so a later destroy/re-home knows which
        // token to revoke) and its expiry (so the scheduled rotation loop knows
        // when to re-mint). Use a targeted update keyed by id so this neither
        // inserts an unsaved model nor flushes unrelated dirty attributes, and
        // does not depend on the Server model's $fillable.
        if ($server->exists) {
            $expiresAt = now()->addSeconds(max(self::TTL_FLOOR_SECONDS, $ttl));

            V5Server::query()->whereKey($server->getKey())->update([
                'agent_token_jti' => $jti,
                'agent_token_expires_at' => $expiresAt,
            ]);
            $server->setAttribute('agent_token_jti', $jti);
            $server->setAttribute('agent_token_expires_at', $expiresAt);
            $server->syncOriginalAttribute('agent_token_jti');
            $server->syncOriginalAttribute('agent_token_expires_at');
        }

        return $token;
    }

    /**
     * Record the server's currently-issued host token jti as revoked AND push
     * the revocation to flux so it rejects the jti at verify immediately.
     *
     * flux now consults a revocation denylist (flux/src/auth.rs `is_revoked`,
     * fed by `POST /v1/tokens/revoke` on the flux UDS), and Laravel pushes to it
     * here. The local `RevokedAgentToken` record remains the source of truth
     * Laravel owns; the flux push is best-effort — if flux is unreachable the
     * revocation is logged and the local record still stands, with the short TTL
     * and hourly rotation bounding the exposure until flux is reachable again.
     */
    public function revoke(V5Server $server): void
    {
        $jti = $server->agent_token_jti;

        if (! is_string($jti) || $jti === '') {
            return;
        }

        $expiresAt = $server->agent_token_expires_at;
        $expiresAtUnix = $expiresAt instanceof \DateTimeInterface ? $expiresAt->getTimestamp() : null;

        RevokedAgentToken::query()->updateOrCreate(
            ['jti' => $jti],
            [
                'server_id' => $server->id,
                'revoked_at' => now(),
                'expires_at' => $expiresAt,
            ]
        );

        // Best-effort: a destroy/teardown must never fail because flux is down.
        try {
            app(FluxClient::class)->revokeToken($jti, $expiresAtUnix);
        } catch (\Throwable $exception) {
            Log::warning('Failed to push agent token revocation to Flux.', [
                'server_id' => $server->id,
                'jti' => $jti,
                'error' => $exception->getMessage(),
            ]);
        }

        if ($server->exists) {
            V5Server::query()->whereKey($server->getKey())->update(['agent_token_jti' => null]);
            $server->setAttribute('agent_token_jti', null);
            $server->syncOriginalAttribute('agent_token_jti');
        }
    }

    /**
     * Revoke the server's currently-issued host token. Alias of {@see revoke()}
     * kept as the name the team-teardown job resolves via `method_exists`.
     */
    public function revokeForServer(V5Server $server): void
    {
        $this->revoke($server);
    }

    public function isRevoked(string $jti): bool
    {
        if ($jti === '') {
            return false;
        }

        return RevokedAgentToken::query()->where('jti', $jti)->exists();
    }

    /**
     * The default capability set for production host tokens: the explicit
     * advertised primitive list, unless the emergency escape hatch profile is
     * configured (then that single profile is minted instead).
     *
     * @return array<int, string>
     */
    private function defaultCapabilities(): array
    {
        $profile = config('flux.host_capability_profile');

        if (is_string($profile) && trim($profile) !== '') {
            return [trim($profile)];
        }

        $configured = config('flux.host_capabilities');

        if (is_array($configured) && $configured !== []) {
            return array_values($configured);
        }

        return [self::DEFAULT_PROFILE];
    }

    /**
     * Warn (but do not hard-fail — that could break existing installs) when the
     * private key file is readable by group/other or is not owner-readable. The
     * key should be generated 0600, e.g.:
     *   openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 \
     *     -out storage/app/flux/jwt.priv && chmod 600 storage/app/flux/jwt.priv
     */
    private function assertPrivateKeyPermissions(string $path): void
    {
        $perms = @fileperms($path);

        if ($perms === false) {
            return;
        }

        $mode = $perms & 0777;

        if (($mode & 0077) !== 0 || ($mode & 0400) === 0) {
            Log::warning('Flux JWT private key has insecure permissions.', [
                'path' => $path,
                'mode' => sprintf('%04o', $mode),
                'expected' => '0600',
            ]);
        }
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
