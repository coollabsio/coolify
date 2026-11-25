<?php

namespace App\Jobs;

use App\Actions\V5\Server\PushHostAgentToken;
use App\Enums\V5\ServerStatus;
use App\Models\V5\Server as V5Server;
use App\Services\Flux\AgentTokenIssuer;
use App\Services\Flux\FluxClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Re-mints and delivers a fresh host JWT for one managed server before its
 * on-disk token expires.
 *
 * RPC-FIRST, SSH-FALLBACK: the new token is delivered over the live coold RPC
 * stream by default (Laravel -> flux UDS -> coold's `host.jwt.set` command),
 * because that reuses the already authenticated flux<->coold channel and works
 * while the CURRENT token is still valid — which is exactly when rotation runs
 * (at ~12h remaining, well before the 24h exp). Only if the RPC push fails (the
 * host's stream is down because its token already lapsed, flux rejects the verb,
 * a timeout, etc.) do we fall back to the SSH push, which recovers a node whose
 * token already expired and whose stream is therefore gone.
 *
 * PUSH-THEN-PERSIST: the new token is delivered to the host FIRST, and the
 * server's jti/expires_at are only advanced AFTER a successful delivery via
 * EITHER path. If both delivery paths fail the DB is left untouched, so the old
 * expires_at keeps the server inside the dispatcher's rotation window and the
 * next cycle simply retries — we never advance the watermark on a token the
 * host never received (which would strand the host on the expiring old token
 * until it fully lapsed).
 *
 * NO-REVOKE-ON-ROTATION: the previously issued jti is intentionally NOT revoked
 * here. The old token is still legitimately valid until its own exp and coold
 * may still be connected on it; revoking it would risk cutting the live stream.
 * Revocation belongs to teardown/re-home (RemoveBootstrapMarker), not routine
 * rotation — the old token simply ages out on its own exp.
 */
class V5RotateAgentTokenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * Rotation shares the reconcile queue so the hourly fleet fan-out can never
     * starve user-triggered deploys and bootstraps on the default queue. Set via
     * onQueue() rather than a `$queue` property redeclaration, which the
     * Queueable trait already defines (redeclaring with a default is an
     * incompatible property composition and fatals on PHP 8.5).
     */
    public function __construct(public int $serverId)
    {
        $this->onQueue('v5-reconcile');
    }

    public function handle(): void
    {
        $server = V5Server::query()->with('privateKey')->find($this->serverId);

        if (! $server instanceof V5Server) {
            return;
        }

        if (! $this->isEligible($server)) {
            return;
        }

        $hostId = $server->fluxHostId();

        if ($hostId === '') {
            Log::warning('V5 token rotation skipped: server is missing a Flux host id.', ['server_id' => $server->id]);

            return;
        }

        $ttl = (int) config('flux.host_token_ttl');
        $jti = (string) Str::uuid();

        $token = app(AgentTokenIssuer::class)->issue($hostId, null, $ttl, [
            'jti' => $jti,
            'team_id' => (string) $server->team_id,
            'cluster_id' => (string) $server->cluster_id,
            'server_id' => $hostId,
            'wireguard_management_ip' => (string) $server->wireguard_management_ip,
        ]);

        $delivery = $this->deliverToken($server, $hostId, $token);

        if ($delivery === null) {
            Log::warning('V5 token rotation could not deliver the new host token; leaving the existing token in place.', [
                'server_id' => $server->id,
                'host' => $server->host,
            ]);

            return;
        }

        $server->update([
            'agent_token_jti' => $jti,
            'agent_token_expires_at' => now()->addSeconds($ttl),
        ]);

        Log::debug('V5 token rotation delivered a fresh host token.', [
            'server_id' => $server->id,
            'delivery' => $delivery,
        ]);
    }

    /**
     * Deliver the freshly minted token to the host, preferring the live coold
     * RPC stream and falling back to the SSH push on any RPC failure.
     *
     * @return 'rpc'|'ssh'|null The path that succeeded, or null if both failed.
     */
    private function deliverToken(V5Server $server, string $hostId, string $token): ?string
    {
        try {
            app(FluxClient::class)->pushHostToken($hostId, $token);

            return 'rpc';
        } catch (\Throwable $exception) {
            Log::info('V5 token rotation RPC push failed; falling back to SSH.', [
                'server_id' => $server->id,
                'error' => $exception->getMessage(),
            ]);
        }

        if (PushHostAgentToken::run($server, $token)) {
            return 'ssh';
        }

        return null;
    }

    private function isEligible(V5Server $server): bool
    {
        return $server->status === ServerStatus::Installed->value
            && (bool) $server->has_coold
            && $server->last_bootstrapped_at !== null;
    }
}
