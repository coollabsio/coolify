<?php

namespace App\Jobs;

use App\Actions\V5\Application\DestroyNginxApplication;
use App\Actions\V5\Proxy\StopCaddyIngress;
use App\Actions\V5\Server\RemoveBootstrapMarker;
use App\Enums\V5\ServerStatus;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\V5\Application as V5Application;
use App\Models\V5\Server as V5Server;
use App\Services\Flux\AgentTokenIssuer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Best-effort, on-host teardown for a team that is being deleted.
 *
 * Deleting a v4 Team DB-cascades every v5_servers / v5_applications /
 * v5_container_statuses / v5_resource_connections row (see the
 * cascadeOnDelete() foreign keys in the v5 migrations) WITHOUT running the
 * app-level teardown that the per-resource destroy flows use. That would leave
 * orphaned podman containers, a running Caddy ingress, the WireGuard mesh and
 * coold itself alive on every host with no DB record left to reach them.
 *
 * This job mirrors the ServerController::destroy / ApplicationController::destroy
 * teardown sequence for every v5 server owned by the team:
 *   1. remove each application's container (DestroyNginxApplication),
 *   2. stop the Caddy ingress on ingress servers (StopCaddyIngress),
 *   3. remove the on-host bootstrap identity — marker, host-jwt and Flux
 *      drop-in (RemoveBootstrapMarker).
 *
 * Because the cascade deletes the servers, applications and private keys the
 * moment the team is gone, the payload is captured at dispatch time (from the
 * Team `deleting` hook, which fires BEFORE the rows vanish) as plain arrays,
 * including the SSH private-key material needed to reach each host. The job
 * rebuilds in-memory, non-persisted models from that payload so it can reuse
 * the exact same actions without touching the (now missing) DB rows.
 *
 * BEST-EFFORT / LIMITATIONS: teardown is best-effort. Each host and each action
 * is guarded so a single unreachable host can never abort teardown of the other
 * hosts, and the team deletion itself never fails because of teardown. The
 * payload is fully self-contained (host, SSH creds/private key, applications,
 * token jti + expiry), so a framework-level queue retry is safe — every step is
 * idempotent (podman rm -f / ingress stop / rm -f are all no-ops when the target
 * is already gone).
 *
 * RESIDUAL LIMITATION: a host that is unreachable at team-deletion time orphans
 * its containers, ingress and mesh PERMANENTLY — the DB rows the reconcilers key
 * off are gone, so there is no later reconciliation. The single operator-facing
 * signal is the `Log::error` emitted at the end of handle() listing the host
 * ids/hosts that could not be torn down; grep for "v5 team teardown incomplete"
 * to find them.
 */
class V5TeardownTeamJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A small retry budget: the payload is self-contained and every teardown
     * step is idempotent, so retrying an unreachable host is safe. There is no
     * point retrying forever — the host may simply be gone.
     */
    public int $tries = 3;

    public int $timeout = 300;

    /**
     * @param  array<int, array<string, mixed>>  $servers  Self-contained per-server teardown payload captured before the cascade.
     */
    public function __construct(
        public int $teamId,
        public array $servers,
    ) {}

    /**
     * Collect the team's v5 servers (with their applications and SSH key
     * material) into a self-contained payload and dispatch the teardown job.
     *
     * Must be called from the Team `deleting` hook, while the rows still exist.
     * Returns without dispatching when the team owns no v5 servers.
     */
    public static function dispatchForTeam(Team $team): void
    {
        // Guard against contexts where the v5 tables do not exist (e.g. v4-only
        // schemas) so team deletion is never broken by this teardown.
        if (! Schema::hasTable('v5_servers')) {
            return;
        }

        $servers = V5Server::query()
            ->where('team_id', $team->id)
            ->with('privateKey')
            ->get();

        if ($servers->isEmpty()) {
            return;
        }

        $applicationsByServer = V5Application::query()
            ->where('team_id', $team->id)
            ->whereNotNull('server_id')
            ->get()
            ->groupBy('server_id');

        $payload = $servers->map(function (V5Server $server) use ($applicationsByServer): array {
            return [
                'id' => $server->id,
                'uuid' => $server->uuid,
                'name' => $server->name,
                'host' => $server->host,
                'ssh_user' => $server->ssh_user,
                'ssh_port' => (int) $server->ssh_port,
                'node_address' => $server->node_address,
                'wireguard_management_ip' => $server->wireguard_management_ip,
                'is_ingress' => (bool) $server->is_ingress,
                'ingress_type' => $server->ingress_type,
                'status' => $server->status,
                'last_bootstrapped_at' => $server->last_bootstrapped_at?->toISOString(),
                // Captured before the cascade removes the row so the job can
                // revoke the host token after the DB rows are gone.
                'agent_token_jti' => $server->agent_token_jti,
                'agent_token_expires_at' => $server->agent_token_expires_at?->toISOString(),
                // Encrypted at rest on the model; needed to SSH into the host.
                'private_key' => $server->privateKey instanceof PrivateKey ? $server->privateKey->private_key : null,
                'applications' => ($applicationsByServer[$server->id] ?? collect())
                    ->map(fn (V5Application $application): array => [
                        'id' => $application->id,
                        'container_name' => $application->container_name,
                        'runtime_container_id' => $application->runtime_container_id,
                    ])
                    ->values()
                    ->all(),
            ];
        })->all();

        self::dispatch($team->id, $payload);
    }

    public function handle(): void
    {
        $incompleteHosts = [];

        foreach ($this->servers as $serverPayload) {
            if (! $this->teardownServer($serverPayload)) {
                $incompleteHosts[] = [
                    'server_id' => $serverPayload['id'] ?? null,
                    'host' => $serverPayload['host'] ?? null,
                ];
            }
        }

        // Teardown is best-effort and never fails the job (an unreachable host
        // must not abort the others), so this is the single operator-facing
        // signal that some hosts could not be reached and may now hold orphaned
        // containers/mesh with no DB row left to reconcile them.
        if ($incompleteHosts !== []) {
            Log::error('v5 team teardown incomplete — '.count($incompleteHosts).' host(s) may have orphaned containers/mesh', [
                'team_id' => $this->teamId,
                'hosts' => $incompleteHosts,
            ]);
        }
    }

    /**
     * Tear down a single host. Returns false when any on-host teardown step
     * (container removal, ingress stop, bootstrap-marker removal) failed, so the
     * caller can surface the host as potentially orphaned. Never throws: a
     * single unreachable host must not abort teardown of the other hosts.
     *
     * @param  array<string, mixed>  $serverPayload
     */
    private function teardownServer(array $serverPayload): bool
    {
        $server = $this->reconstructServer($serverPayload);
        $serverId = $serverPayload['id'] ?? null;
        $host = $serverPayload['host'] ?? null;
        $succeeded = true;

        foreach ($serverPayload['applications'] ?? [] as $applicationPayload) {
            try {
                $application = $this->reconstructApplication($applicationPayload, $server);
                DestroyNginxApplication::run($application);
            } catch (\Throwable $exception) {
                $succeeded = false;
                Log::warning('V5 team teardown: failed to remove application container', [
                    'team_id' => $this->teamId,
                    'server_id' => $serverId,
                    'host' => $host,
                    'container_name' => $applicationPayload['container_name'] ?? null,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($server->isIngress() && $server->status === ServerStatus::Installed->value) {
            try {
                StopCaddyIngress::run($server);
            } catch (\Throwable $exception) {
                $succeeded = false;
                Log::warning('V5 team teardown: failed to stop Caddy ingress', [
                    'team_id' => $this->teamId,
                    'server_id' => $serverId,
                    'host' => $host,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (($serverPayload['last_bootstrapped_at'] ?? null) !== null) {
            try {
                if (! RemoveBootstrapMarker::run($server)) {
                    $succeeded = false;
                    Log::warning('V5 team teardown: could not remove on-host bootstrap identity over SSH', [
                        'team_id' => $this->teamId,
                        'server_id' => $serverId,
                        'host' => $host,
                    ]);
                }
            } catch (\Throwable $exception) {
                $succeeded = false;
                Log::warning('V5 team teardown: bootstrap marker removal threw', [
                    'team_id' => $this->teamId,
                    'server_id' => $serverId,
                    'host' => $host,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        // Revocation is best-effort and independent of the on-host cleanup: a
        // failed flux push does not mean the host is orphaned, so it never flips
        // $succeeded (it is logged separately inside AgentTokenIssuer::revoke).
        $this->revokeAgentTokenIfSupported($server, $serverId, $host);

        return $succeeded;
    }

    /**
     * Reconstruct a non-persisted V5Server (with its private key relation
     * pre-set) so the teardown actions never hit the deleted DB rows.
     *
     * @param  array<string, mixed>  $serverPayload
     */
    private function reconstructServer(array $serverPayload): V5Server
    {
        $server = new V5Server;
        $server->forceFill([
            'id' => $serverPayload['id'] ?? null,
            'uuid' => $serverPayload['uuid'] ?? null,
            'name' => $serverPayload['name'] ?? null,
            'host' => $serverPayload['host'] ?? null,
            'ssh_user' => $serverPayload['ssh_user'] ?? null,
            'ssh_port' => $serverPayload['ssh_port'] ?? 22,
            'node_address' => $serverPayload['node_address'] ?? null,
            'wireguard_management_ip' => $serverPayload['wireguard_management_ip'] ?? null,
            'is_ingress' => (bool) ($serverPayload['is_ingress'] ?? false),
            'ingress_type' => $serverPayload['ingress_type'] ?? null,
            'status' => $serverPayload['status'] ?? null,
            'agent_token_jti' => $serverPayload['agent_token_jti'] ?? null,
            'agent_token_expires_at' => $serverPayload['agent_token_expires_at'] ?? null,
        ]);
        // Non-persisted: StopCaddyIngress / the actions must not try to update a
        // row that the cascade already removed.
        $server->exists = false;

        $privateKeyMaterial = $serverPayload['private_key'] ?? null;
        if (is_string($privateKeyMaterial) && $privateKeyMaterial !== '') {
            $privateKey = new PrivateKey;
            $privateKey->forceFill(['private_key' => $privateKeyMaterial]);
            $server->setRelation('privateKey', $privateKey);
        } else {
            $server->setRelation('privateKey', null);
        }

        return $server;
    }

    /**
     * @param  array<string, mixed>  $applicationPayload
     */
    private function reconstructApplication(array $applicationPayload, V5Server $server): V5Application
    {
        $application = new V5Application;
        $application->forceFill([
            'id' => $applicationPayload['id'] ?? null,
            'container_name' => $applicationPayload['container_name'] ?? null,
            'runtime_container_id' => $applicationPayload['runtime_container_id'] ?? null,
            'server_id' => $server->id,
        ]);
        $application->exists = false;
        $application->setRelation('server', $server);

        return $application;
    }

    /**
     * If a coold-side agent-token revocation ever lands on AgentTokenIssuer,
     * call it best-effort. Guarded so this job never hard-depends on a method
     * that may not exist yet.
     */
    private function revokeAgentTokenIfSupported(V5Server $server, mixed $serverId, mixed $host): void
    {
        if (! method_exists(AgentTokenIssuer::class, 'revokeForServer')) {
            return;
        }

        try {
            app(AgentTokenIssuer::class)->revokeForServer($server);
        } catch (\Throwable $exception) {
            Log::warning('V5 team teardown: agent token revocation failed', [
                'team_id' => $this->teamId,
                'server_id' => $serverId,
                'host' => $host,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
