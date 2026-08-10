<?php

namespace App\Http\Controllers\V5;

use App\Actions\V5\Proxy\StartCaddyIngress;
use App\Actions\V5\Proxy\StopCaddyIngress;
use App\Actions\V5\Server\RemoveBootstrapMarker;
use App\Enums\V5\ServerStatus;
use App\Events\V5ClusterUpdated;
use App\Http\Controllers\Controller;
use App\Http\Controllers\V5\Concerns\HandlesIngressSyncErrors;
use App\Http\Controllers\V5\Concerns\ResolvesCurrentTeam;
use App\Http\Controllers\V5\Concerns\ValidatesBuilderConfiguration;
use App\Jobs\V5BootstrapServerJob;
use App\Models\PrivateKey;
use App\Models\V5\Application as V5Application;
use App\Models\V5\Cluster as V5Cluster;
use App\Models\V5\Server as V5Server;
use App\Rules\ValidServerIp;
use App\Services\Flux\AgentTokenIssuer;
use App\Services\Flux\FluxClient;
use App\Support\V5\ClusterSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\Rule;

class ServerController extends Controller
{
    use HandlesIngressSyncErrors;
    use ResolvesCurrentTeam;
    use ValidatesBuilderConfiguration;

    public function store(Request $request, V5Cluster $cluster): JsonResponse
    {
        $currentTeam = $this->currentTeamOrFail($request);
        $this->authorize('create', [V5Server::class, $currentTeam, $cluster]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'host' => [
                'required',
                'string',
                'max:255',
                $this->noControlCharactersRule(),
                new ValidServerIp,
                Rule::unique('v5_servers', 'host')
                    ->where('team_id', $currentTeam->id)
                    ->where('ssh_port', (int) $request->input('ssh_port', 22)),
            ],
            'ssh_user' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/', $this->noControlCharactersRule()],
            'ssh_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'private_key_uuid' => [
                'required',
                'string',
                Rule::exists('private_keys', 'uuid')->where('team_id', $currentTeam->id),
            ],
            'node_address' => [
                'nullable',
                'string',
                'max:255',
                $this->noControlCharactersRule(),
                new ValidServerIp,
                Rule::unique('v5_servers', 'node_address')->where('team_id', $currentTeam->id),
            ],
            'builder_enabled' => ['sometimes', 'boolean'],
            'builder_capacity' => $this->builderCapacityRules(
                $this->requestedBuilderEnabled($request, $cluster->builder_enabled)
            ),
            'builder_cpu_quota' => ['sometimes', 'string', 'max:32'],
            'wireguard_listen_port_override' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'wireguard_endpoint_override' => [
                'nullable',
                'string',
                'max:255',
                $this->noControlCharactersRule(),
                $this->hostPortRule(),
                Rule::unique('v5_servers', 'wireguard_endpoint_override')->where('cluster_id', $cluster->id),
            ],
            'ingress_enabled' => ['sometimes', 'boolean'],
            'ingress_type' => [
                Rule::requiredIf(fn () => $request->boolean('ingress_enabled')),
                'nullable',
                'string',
                Rule::in(['caddy']),
            ],
        ]);

        $capacity = $this->clusterServerCapacity($cluster);

        if ($capacity !== null && $cluster->servers()->count() >= $capacity) {
            return response()->json([
                'message' => "This cluster's network pools are full ({$capacity} server(s) max). Grow the pools or remove a server first.",
            ], 422);
        }

        $builderEnabled = (bool) ($validated['builder_enabled'] ?? $cluster->builder_enabled);
        $ingressEnabled = (bool) ($validated['ingress_enabled'] ?? false);
        $ingressType = $ingressEnabled ? $validated['ingress_type'] : null;
        $builderCapacity = (int) ($validated['builder_capacity'] ?? $cluster->builder_capacity);
        $builderCpuQuota = $validated['builder_cpu_quota'] ?? $cluster->builder_cpu_quota;
        $devWireguardOverrides = $this->devLimaWireguardOverrides($validated['host'], (int) $validated['ssh_port']);
        $privateKey = PrivateKey::query()
            ->where('team_id', $currentTeam->id)
            ->where('uuid', $validated['private_key_uuid'])
            ->firstOrFail();

        V5Server::query()->create([
            'team_id' => $currentTeam->id,
            'cluster_id' => $cluster->id,
            'created_by_user_id' => $request->user()->id,
            'name' => $validated['name'],
            'host' => $validated['host'],
            'ssh_user' => $validated['ssh_user'],
            'ssh_port' => $validated['ssh_port'],
            'private_key_id' => $privateKey->id,
            'status' => ServerStatus::Added->value,
            'ingress_type' => $ingressType,
            'is_ingress' => $ingressEnabled,
            'builder_enabled' => $builderEnabled,
            'builder_capacity' => $builderCapacity,
            'builder_cpu_quota' => $builderCpuQuota,
            'node_address' => $validated['node_address'] ?? $validated['host'],
            'wireguard_listen_port_override' => $validated['wireguard_listen_port_override'] ?? $devWireguardOverrides['listen_port'],
            'wireguard_endpoint_override' => $validated['wireguard_endpoint_override'] ?? $devWireguardOverrides['endpoint'],
        ]);

        return response()->json([
            'cluster' => app(ClusterSerializer::class)->serializeFresh($cluster),
        ], 201);
    }

    public function update(Request $request, V5Cluster $cluster, V5Server $server): JsonResponse
    {
        $currentTeam = $this->currentTeamOrFail($request);
        $this->authorize('update', [$server, $currentTeam, $cluster]);

        $validated = $request->validate([
            'builder_enabled' => ['required', 'boolean'],
            'builder_capacity' => $this->builderCapacityRules(
                $request->boolean('builder_enabled'),
                required: true
            ),
            'builder_cpu_quota' => ['required', 'string', 'max:32'],
            'ingress_enabled' => ['sometimes', 'boolean'],
            'ingress_type' => [
                Rule::requiredIf(fn () => $request->boolean('ingress_enabled')),
                'nullable',
                'string',
                Rule::in(['caddy']),
            ],
        ]);

        $wasIngress = $server->isIngress();
        $builderEnabled = (bool) $validated['builder_enabled'];
        $ingressEnabled = (bool) ($validated['ingress_enabled'] ?? $wasIngress);
        $ingressType = $ingressEnabled ? ($validated['ingress_type'] ?? $server->ingress_type ?? 'caddy') : null;
        $originalServerAttributes = $server->only([
            'is_ingress',
            'ingress_type',
            'ingress_status',
            'builder_enabled',
            'builder_capacity',
            'builder_cpu_quota',
        ]);

        // Stop the ingress before persisting the change: StopCaddyIngress needs
        // the server's current ingress state, and a failed stop must leave the
        // capability untouched.
        if ($wasIngress && ! $ingressEnabled && $server->status === ServerStatus::Installed->value) {
            try {
                StopCaddyIngress::run($server);
            } catch (\RuntimeException $exception) {
                return $this->ingressSyncErrorResponse($exception);
            }
        }

        $server->update([
            'is_ingress' => $ingressEnabled,
            'ingress_type' => $ingressType,
            'builder_enabled' => $builderEnabled,
            'builder_capacity' => (int) $validated['builder_capacity'],
            'builder_cpu_quota' => $validated['builder_cpu_quota'],
        ]);

        $server->refresh();

        if (! $wasIngress && $ingressEnabled && $server->status === ServerStatus::Installed->value) {
            try {
                StartCaddyIngress::run($server);
            } catch (\RuntimeException $exception) {
                $server->update($originalServerAttributes);

                return $this->ingressSyncErrorResponse($exception);
            }
        }

        return response()->json([
            'cluster' => app(ClusterSerializer::class)->serializeFresh($cluster),
        ]);
    }

    public function check(Request $request, V5Cluster $cluster, V5Server $server): JsonResponse
    {
        $currentTeam = $this->currentTeamOrFail($request);
        $this->authorize('check', [$server, $currentTeam, $cluster]);

        if (! $server->privateKey instanceof PrivateKey) {
            return response()->json([
                'status' => 'failed',
                'output' => 'No private key is attached to this server.',
                'checkedAt' => now()->toJSON(),
            ]);
        }

        $keyDirectory = storage_path('app/ssh/keys');
        if (! is_dir($keyDirectory)) {
            mkdir($keyDirectory, 0700, true);
        }

        $keyLocation = tempnam($keyDirectory, 'v5_ssh_key_');
        if ($keyLocation === false) {
            return response()->json([
                'status' => 'failed',
                'output' => 'Could not create a temporary SSH key file.',
                'checkedAt' => now()->toJSON(),
            ]);
        }

        file_put_contents($keyLocation, $server->privateKey->private_key);
        chmod($keyLocation, 0600);

        $target = "{$server->ssh_user}@{$server->host}";
        $command = [
            'ssh',
            '-o',
            'BatchMode=yes',
            '-o',
            'LogLevel=ERROR',
            '-o',
            'StrictHostKeyChecking=no',
            '-o',
            'UserKnownHostsFile=/dev/null',
            '-o',
            'ConnectTimeout=10',
            '-o',
            'IdentitiesOnly=yes',
            '-i',
            $keyLocation,
            '-p',
            (string) $server->ssh_port,
            $target,
            "printf 'SSH connection OK\n'; hostname; uname -srm; command -v docker || true; command -v podman || true",
        ];

        try {
            $result = Process::timeout(15)->run($command);
            $output = trim($result->output()."\n".$result->errorOutput());
            $status = $result->successful() ? 'reachable' : 'failed';
        } catch (\Throwable $e) {
            $output = $e->getMessage();
            $status = 'failed';
        } finally {
            @unlink($keyLocation);
        }

        return response()->json([
            'status' => $status,
            'output' => str($output !== '' ? $output : 'No output returned.')->limit(10000)->toString(),
            'checkedAt' => now()->toJSON(),
        ]);
    }

    public function restartCoold(Request $request, V5Cluster $cluster, V5Server $server, AgentTokenIssuer $agentTokenIssuer, FluxClient $fluxClient): JsonResponse
    {
        $currentTeam = $this->currentTeamOrFail($request);
        $this->authorize('restartCoold', [$server, $currentTeam, $cluster]);

        $server->loadMissing('privateKey');

        if (! $server->privateKey instanceof PrivateKey) {
            return response()->json([
                'message' => 'No private key is attached to this server.',
            ], 422);
        }

        try {
            $token = $agentTokenIssuer->issueForServer($server);
            $output = $this->restartCooldOverSsh($server, $token);
        } catch (\Throwable $e) {
            Log::warning('V5 coold restart over SSH failed', [
                'server_id' => $server->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => str($e->getMessage() !== '' ? $e->getMessage() : 'Could not restart coold over SSH.')->limit(10000)->toString(),
            ], 502);
        }

        $connected = false;

        try {
            usleep(500_000);
            $fluxClient->cooldLogs($server->fluxHostId(), 1);
            $connected = true;
            $server->forceFill([
                'status' => ServerStatus::Installed->value,
                'last_status_check' => 'flux',
                'last_status_output' => 'coold restarted over SSH and reconnected to Flux.',
            ])->save();
        } catch (\Throwable $e) {
            Log::info('V5 coold restart succeeded but Flux reconnect is not confirmed yet', [
                'server_id' => $server->id,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'cluster' => app(ClusterSerializer::class)->serializeFresh($cluster),
            'output' => $output,
            'connected' => $connected,
            'restartedAt' => now()->toJSON(),
        ]);
    }

    private function restartCooldOverSsh(V5Server $server, string $token): string
    {
        $encodedToken = base64_encode($token);
        $script = implode(PHP_EOL, [
            'set -e',
            "SUDO=''",
            'if [ "$(id -u)" != "0" ]; then SUDO="sudo -n"; fi',
            '$SUDO mkdir -p /etc/coolify',
            'printf %s '.escapeshellarg($encodedToken).' | base64 -d | $SUDO tee /etc/coolify/host-jwt >/dev/null',
            '$SUDO chmod 600 /etc/coolify/host-jwt',
            '$SUDO systemctl reset-failed coold.service || true',
            '$SUDO systemctl restart coold.service',
            '$SUDO systemctl is-active coold.service',
            '$SUDO systemctl status coold.service --no-pager -l | sed -n "1,18p"',
        ]);

        return $this->runServerSshCommand($server, $script, 'SSH coold restart command failed.', 45);
    }

    public function cooldLogs(Request $request, V5Cluster $cluster, V5Server $server, FluxClient $fluxClient): JsonResponse
    {
        $currentTeam = $this->currentTeamOrFail($request);
        $this->authorize('viewDiagnostics', [$server, $currentTeam, $cluster]);

        $validated = $request->validate([
            'tail' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        $hostId = $server->fluxHostId();

        if (! is_string($hostId) || $hostId === '') {
            return response()->json([
                'message' => 'This server is missing its Flux host id.',
            ], 422);
        }

        try {
            $output = $fluxClient->cooldLogs($hostId, (int) ($validated['tail'] ?? 200));
        } catch (\Throwable $e) {
            Log::warning('V5 coold logs request failed', [
                'server_id' => $server->id,
                'message' => $e->getMessage(),
            ]);

            if ($server->privateKey instanceof PrivateKey) {
                try {
                    return response()->json([
                        'output' => $this->cooldLogsOverSsh($server, (int) ($validated['tail'] ?? 200)),
                        'source' => 'ssh',
                        'fetchedAt' => now()->toJSON(),
                    ]);
                } catch (\Throwable $sshException) {
                    Log::warning('V5 coold logs SSH fallback failed', [
                        'server_id' => $server->id,
                        'message' => $sshException->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'message' => 'Could not fetch coold logs through Flux. Check the Flux and coold status, then try again.',
            ], 502);
        }

        return response()->json([
            'output' => $output,
            'source' => 'flux',
            'fetchedAt' => now()->toJSON(),
        ]);
    }

    private function cooldLogsOverSsh(V5Server $server, int $tail): string
    {
        return $this->runServerSshCommand(
            $server,
            'sudo -n journalctl -u coold -n '.max(1, min($tail, 1000)).' --no-pager -q || journalctl -u coold -n '.max(1, min($tail, 1000)).' --no-pager -q',
            'SSH coold log command failed.',
        );
    }

    private function runServerSshCommand(V5Server $server, string $remoteCommand, string $failureMessage, int $timeout = 15): string
    {
        $keyDirectory = storage_path('app/ssh/keys');
        if (! is_dir($keyDirectory)) {
            mkdir($keyDirectory, 0700, true);
        }

        $keyLocation = tempnam($keyDirectory, 'v5_ssh_key_');
        if ($keyLocation === false) {
            throw new \RuntimeException('Could not create a temporary SSH key file.');
        }

        file_put_contents($keyLocation, $server->privateKey->private_key);
        chmod($keyLocation, 0600);

        try {
            $result = Process::timeout($timeout)->run([
                'ssh',
                '-o',
                'BatchMode=yes',
                '-o',
                'LogLevel=ERROR',
                '-o',
                'StrictHostKeyChecking=no',
                '-o',
                'UserKnownHostsFile=/dev/null',
                '-o',
                'ConnectTimeout=10',
                '-o',
                'IdentitiesOnly=yes',
                '-i',
                $keyLocation,
                '-p',
                (string) $server->ssh_port,
                "{$server->ssh_user}@{$server->host}",
                $remoteCommand,
            ]);
            $output = trim($result->output()."\n".$result->errorOutput());

            if (! $result->successful()) {
                throw new \RuntimeException($output !== '' ? $output : $failureMessage);
            }

            return str($output)->limit(10000)->toString();
        } finally {
            @unlink($keyLocation);
        }
    }

    public function corrosionTables(Request $request, V5Cluster $cluster, V5Server $server, FluxClient $fluxClient): JsonResponse
    {
        $currentTeam = $this->currentTeamOrFail($request);
        $this->authorize('viewDiagnostics', [$server, $currentTeam, $cluster]);

        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        $hostId = $server->fluxHostId();

        if (! is_string($hostId) || $hostId === '') {
            return response()->json([
                'message' => 'This server is missing its Flux host id.',
            ], 422);
        }

        try {
            $output = $fluxClient->corrosionTables($hostId, (int) ($validated['limit'] ?? 200));
        } catch (\Throwable $e) {
            Log::warning('V5 corrosion tables request failed', [
                'server_id' => $server->id,
                'message' => $e->getMessage(),
            ]);

            if ($server->privateKey instanceof PrivateKey) {
                try {
                    return response()->json([
                        'output' => $this->corrosionTablesOverSsh($server, $cluster, (int) ($validated['limit'] ?? 200)),
                        'source' => 'ssh',
                        'fetchedAt' => now()->toJSON(),
                    ]);
                } catch (\Throwable $sshException) {
                    Log::warning('V5 corrosion tables SSH fallback failed', [
                        'server_id' => $server->id,
                        'message' => $sshException->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'message' => 'Could not fetch corrosion tables through Flux. Check the Flux and coold status, then try again.',
            ], 502);
        }

        return response()->json([
            'output' => $output,
            'source' => 'flux',
            'fetchedAt' => now()->toJSON(),
        ]);
    }

    private function corrosionTablesOverSsh(V5Server $server, V5Cluster $cluster, int $limit): string
    {
        $limit = max(1, min($limit, 1000));
        $script = <<<'PYTHON'
python3 - <<'PY'
import json
import urllib.request

limit = __LIMIT__
url = "http://127.0.0.1:__PORT__/v1/queries"

def query(sql):
    request = urllib.request.Request(
        url,
        data=json.dumps([sql, []]).encode(),
        headers={"Content-Type": "application/json"},
    )
    with urllib.request.urlopen(request, timeout=10) as response:
        return json.loads(response.read().decode())

def quote_identifier(value):
    return '"' + value.replace('"', '""') + '"'

tables = []
for row in query("SELECT name FROM sqlite_schema WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"):
    name = row[0] if row else None
    if not isinstance(name, str):
        continue

    identifier = quote_identifier(name)
    columns = [column[1] for column in query(f"PRAGMA table_info({identifier})") if len(column) > 1]
    rows = query(f"SELECT * FROM {identifier} LIMIT {limit}")
    tables.append({"name": name, "columns": columns, "rows": rows})

print(json.dumps({"limit": limit, "tables": tables}, separators=(",", ":")))
PY
PYTHON;

        return $this->runServerSshCommand($server, str_replace(
            ['__LIMIT__', '__PORT__'],
            [(string) $limit, (string) $cluster->corrosion_api_port],
            $script,
        ), 'SSH corrosion table command failed.');
    }

    public function firewallRules(Request $request, V5Cluster $cluster, V5Server $server, FluxClient $fluxClient): JsonResponse
    {
        $currentTeam = $this->currentTeamOrFail($request);
        $this->authorize('viewDiagnostics', [$server, $currentTeam, $cluster]);

        $validated = $request->validate([
            'namespace' => ['sometimes', 'string', 'max:63'],
        ]);

        $hostId = $server->fluxHostId();

        if (! is_string($hostId) || $hostId === '') {
            return response()->json([
                'message' => 'This server is missing its Flux host id.',
            ], 422);
        }

        try {
            $rules = $fluxClient->listFirewallRules($hostId, (string) ($validated['namespace'] ?? ''));
        } catch (\Throwable $e) {
            Log::warning('V5 firewall rules request failed', [
                'server_id' => $server->id,
                'message' => $e->getMessage(),
            ]);

            if ($server->privateKey instanceof PrivateKey) {
                try {
                    return response()->json([
                        'rules' => $this->firewallRulesOverSsh($server, (string) ($validated['namespace'] ?? '')),
                        'source' => 'ssh',
                        'fetchedAt' => now()->toJSON(),
                    ]);
                } catch (\Throwable $sshException) {
                    Log::warning('V5 firewall rules SSH fallback failed', [
                        'server_id' => $server->id,
                        'message' => $sshException->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'message' => 'Could not fetch firewall rules through Flux. Check the Flux and coold status, then try again.',
            ], 502);
        }

        return response()->json([
            'rules' => $rules,
            'source' => 'flux',
            'fetchedAt' => now()->toJSON(),
        ]);
    }

    /**
     * @return array<int, array{id: string, namespace: string, src: string, dst: string, proto: string, port: int}>
     */
    private function firewallRulesOverSsh(V5Server $server, string $namespace): array
    {
        $script = str_replace('__NAMESPACE__', json_encode($namespace, JSON_THROW_ON_ERROR), <<<'PYTHON'
python3 - <<'PY'
import json
from pathlib import Path

namespace = __NAMESPACE__
path = Path("/etc/coolify/firewall-rules.tsv")
rules = []

if path.exists():
    for line in path.read_text().splitlines():
        parts = line.split("\t")
        if len(parts) == 6:
            rule_id, rule_namespace, src, dst, proto, port = parts
        elif len(parts) == 5:
            rule_id = ""
            rule_namespace, src, dst, proto, port = parts
        else:
            continue

        if namespace and rule_namespace != namespace:
            continue

        try:
            port = int(port)
        except ValueError:
            continue

        rules.append({
            "id": rule_id,
            "namespace": rule_namespace,
            "src": src,
            "dst": dst,
            "proto": proto,
            "port": port,
        })

print(json.dumps(rules, separators=(",", ":")))
PY
PYTHON);

        $output = $this->runServerSshCommand($server, $script, 'SSH firewall rules command failed.');
        $rules = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        return is_array($rules) ? $rules : [];
    }

    public function bootstrap(Request $request, V5Cluster $cluster, V5Server $server): JsonResponse
    {
        $currentTeam = $this->currentTeamOrFail($request);
        $this->authorize('bootstrap', [$server, $currentTeam, $cluster]);

        // Fail fast: the bootstrap job hard-fails on Flux enrollment (after
        // the WireGuard mesh is already built) when no Flux URL is configured.
        if (trim((string) config('coold.flux_url', '')) === '') {
            return response()->json([
                'message' => 'COOLIFY_COOLD_FLUX_URL is not configured, so bootstrapped servers cannot be enrolled into Flux. Set it and retry the bootstrap.',
            ], 422);
        }

        if ($server->last_bootstrapped_at !== null) {
            return response()->json([
                'message' => 'This server is already bootstrapped.',
            ], 409);
        }

        $installedServers = $cluster->servers()
            ->with('privateKey')
            ->whereNotNull('last_bootstrapped_at')
            ->orderBy('name')
            ->get();
        $server->load('privateKey');
        $servers = $installedServers->toBase()
            ->push($server)
            ->unique('id')
            ->values();

        if ($servers->contains(fn (V5Server $server) => ! $server->privateKey instanceof PrivateKey)) {
            return response()->json([
                'message' => 'The new server and every already-bootstrapped server in this cluster must have a private key before extending the cluster.',
            ], 422);
        }

        $claim = DB::transaction(function () use ($cluster, $server, $installedServers): array {
            $clusterServers = $cluster->servers()->lockForUpdate()->get();
            $activeServer = $clusterServers->first(fn (V5Server $candidate): bool => $this->hasActiveBootstrapClaim($candidate));

            if ($activeServer instanceof V5Server) {
                return ['claimed' => false, 'active_server_id' => $activeServer->id];
            }

            // Sweep provably dead claims (lost job or killed worker) so retries
            // are possible and the UI reflects reality.
            $clusterServers
                ->filter(fn (V5Server $candidate): bool => in_array($candidate->last_bootstrap_status, ['queued', 'running'], true))
                ->each(fn (V5Server $candidate) => $candidate->update([
                    'last_bootstrap_status' => 'failed',
                    'last_bootstrap_output' => 'The previous bootstrap attempt timed out or its worker died. Retry the bootstrap.',
                ]));

            $server->update([
                'last_bootstrap_action' => $installedServers->isEmpty() ? 'bootstrap' : 'extend',
                'last_bootstrap_status' => 'queued',
                'last_bootstrap_output' => "Queued Coolify bootstrap for {$server->name}.",
                'last_bootstrap_ran_at' => now(),
            ]);

            return ['claimed' => true, 'active_server_id' => null];
        });

        if (! $claim['claimed']) {
            return response()->json([
                'cluster' => app(ClusterSerializer::class)->serializeFresh($cluster),
                'message' => $claim['active_server_id'] === $server->id
                    ? 'Bootstrap is already queued or running for this server.'
                    : 'Another server bootstrap is already queued or running for this cluster.',
            ], 409);
        }

        V5ClusterUpdated::dispatch($currentTeam->id, $cluster->id);
        V5BootstrapServerJob::dispatch($cluster->id, $server->id);

        return response()->json([
            'cluster' => app(ClusterSerializer::class)->serializeFresh($cluster),
            'message' => 'Bootstrap queued.',
        ], 202);
    }

    public function destroy(Request $request, V5Cluster $cluster, V5Server $server): Response|JsonResponse
    {
        $currentTeam = $this->currentTeamOrFail($request);
        $this->authorize('delete', [$server, $currentTeam, $cluster]);

        if (V5Application::query()->where('server_id', $server->id)->exists()) {
            return response()->json([
                'message' => 'Delete or move applications from this server before deleting it.',
            ], 422);
        }

        $warning = null;

        if ($server->last_bootstrapped_at !== null) {
            if ($server->isIngress() && $server->status === ServerStatus::Installed->value) {
                try {
                    StopCaddyIngress::run($server);
                } catch (\Throwable $exception) {
                    report($exception);
                    $warning = 'Could not stop the Caddy ingress on the server before deleting it.';
                }
            }

            if (! RemoveBootstrapMarker::run($server)) {
                $warning = 'Could not clean up the server over SSH. Remove /etc/coolify/v5-node.json manually before re-adding this server.';
            }
        }

        $server->delete();

        return response()->json(array_filter([
            'cluster' => app(ClusterSerializer::class)->serializeFresh($cluster),
            'warning' => $warning,
        ]));
    }

    /**
     * A queued claim is active while the job could still pick it up; a running
     * claim is active until the job timeout (plus margin) has passed. Anything
     * older is provably dead because the job runs with $tries = 1.
     */
    private function hasActiveBootstrapClaim(V5Server $server): bool
    {
        $ranAt = $server->last_bootstrap_ran_at;

        return match ($server->last_bootstrap_status) {
            'queued' => $ranAt !== null && $ranAt->gt(now()->subMinutes(15)),
            'running' => $ranAt !== null && $ranAt->gt(now()->subSeconds(V5BootstrapServerJob::TIMEOUT_SECONDS + 300)),
            default => false,
        };
    }

    /**
     * @return array{listen_port: int|null, endpoint: string|null}
     */
    private function devLimaWireguardOverrides(string $host, int $sshPort): array
    {
        if (! app()->environment(['local', 'development', 'testing']) || $host !== 'host.docker.internal') {
            return ['listen_port' => null, 'endpoint' => null];
        }

        if ($sshPort < 60001 || $sshPort > 60009) {
            return ['listen_port' => null, 'endpoint' => null];
        }

        $wireguardPort = $sshPort - 8180;

        return [
            'listen_port' => $wireguardPort,
            'endpoint' => "host.lima.internal:{$wireguardPort}",
        ];
    }

    private function clusterServerCapacity(V5Cluster $cluster): ?int
    {
        $namespaceCount = max(1, count($cluster->namespaces ?? V5Cluster::DEFAULT_NAMESPACES));

        [, $poolPrefix] = array_pad(explode('/', (string) $cluster->container_network_pool, 2), 2, null);
        $containerPrefix = (int) $cluster->container_network_prefix;

        if (! is_string($poolPrefix) || ! ctype_digit($poolPrefix) || $containerPrefix < (int) $poolPrefix || $containerPrefix > 32) {
            return null;
        }

        $containerCapacity = intdiv(2 ** ($containerPrefix - (int) $poolPrefix), $namespaceCount);

        [, $managementPrefix] = array_pad(explode('/', (string) $cluster->wireguard_management_pool, 2), 2, null);
        $managementCapacity = is_string($managementPrefix) && ctype_digit($managementPrefix) && (int) $managementPrefix <= 30
            ? (2 ** (32 - (int) $managementPrefix)) - 2
            : null;

        return $managementCapacity === null ? $containerCapacity : min($containerCapacity, $managementCapacity);
    }

    private function noControlCharactersRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                $fail('The :attribute contains invalid control characters.');
            }
        };
    }

    private function hostPortRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            if (! is_string($value)) {
                $fail('The :attribute must be in host:port format.');

                return;
            }

            $value = trim($value);

            if (preg_match('/^\[(?<host>.+)]:(?<port>\d+)$/', $value, $matches) === 1) {
                $host = trim((string) $matches['host']);
                $port = trim((string) $matches['port']);
            } else {
                $separatorPosition = strrpos($value, ':');

                if ($separatorPosition === false) {
                    $fail('The :attribute must be in host:port format.');

                    return;
                }

                $host = trim(substr($value, 0, $separatorPosition));
                $port = trim(substr($value, $separatorPosition + 1));

                if (str_contains($host, ':')) {
                    $fail('The :attribute must use [ipv6]:port format for IPv6 addresses.');

                    return;
                }
            }

            if ($host === '' || $port === '' || ! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
                $fail('The :attribute must be in host:port format.');

                return;
            }

            $failed = false;
            (new ValidServerIp)->validate($attribute, $host, function () use (&$failed): void {
                $failed = true;
            });

            if ($failed) {
                $fail('The :attribute must be in host:port format.');
            }
        };
    }
}
