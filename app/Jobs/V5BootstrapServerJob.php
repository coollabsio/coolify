<?php

namespace App\Jobs;

use App\Events\V5ClusterUpdated;
use App\Models\PrivateKey;
use App\Models\V5\Cluster as V5Cluster;
use App\Models\V5\Server as V5Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class V5BootstrapServerJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const BOOTSTRAP_MARKER_PATH = '/etc/coolify/v5-node.json';

    public int $tries = 1;

    public int $timeout = 7200;

    public function __construct(public int $clusterId, public int $serverId) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("v5-bootstrap-server-{$this->serverId}"))->expireAfter(7200)->dontRelease()];
    }

    public function handle(): void
    {
        $cluster = V5Cluster::query()->findOrFail($this->clusterId);
        $server = V5Server::query()->with('privateKey')->findOrFail($this->serverId);

        if ($server->cluster_id !== $cluster->id || $server->last_bootstrapped_at !== null) {
            return;
        }

        $installedServers = $cluster->servers()
            ->with('privateKey')
            ->whereNotNull('last_bootstrapped_at')
            ->orderBy('name')
            ->get();
        $action = $installedServers->isEmpty() ? 'bootstrap' : 'extend';
        $servers = $installedServers->toBase()
            ->push($server)
            ->unique('id')
            ->values();

        if ($servers->contains(fn (V5Server $server) => ! $server->privateKey instanceof PrivateKey)) {
            $this->markFailed($server, $action, 'The new server and every already-bootstrapped server in this cluster must have a private key before extending the cluster.');

            return;
        }

        $server->update([
            'last_bootstrap_action' => $action,
            'last_bootstrap_status' => 'running',
            'last_bootstrap_output' => "Starting Coolify CLI {$action} for {$server->name}...",
            'last_bootstrap_ran_at' => now(),
        ]);
        $this->broadcastClusterUpdated($server);

        $keyDirectory = storage_path('app/ssh/keys');
        if (! is_dir($keyDirectory)) {
            mkdir($keyDirectory, 0700, true);
        }

        $tempDirectory = $keyDirectory.'/v5_bootstrap_'.str()->random(16);
        if (! mkdir($tempDirectory, 0700, true) && ! is_dir($tempDirectory)) {
            $this->markFailed($server, $action, 'Could not create a temporary SSH configuration directory.');

            return;
        }

        try {
            $sshConfigLocation = $this->writeBootstrapSshConfig($servers, $tempDirectory);
            $existingBootstrap = $this->detectExistingBootstrap($server, $sshConfigLocation);

            if (($existingBootstrap['cluster_id'] ?? null) !== null) {
                if ((string) $existingBootstrap['cluster_id'] !== (string) $cluster->id) {
                    $this->markFailed($server, $action, 'This server is already bootstrapped for another cluster. Reset the host bootstrap state before joining this cluster.');

                    return;
                }

                $this->adoptExistingBootstrap($server, $existingBootstrap);

                return;
            }

            $result = Process::timeout(max(60, (int) $cluster->builder_timeout_secs + 120))
                ->run($this->bootstrapCommand($cluster, $servers, $server, $sshConfigLocation, $action));
            $output = trim($result->output()."\n".$result->errorOutput());
            $successful = $result->successful();

            $server->update([
                'last_bootstrap_action' => $action,
                'last_bootstrap_status' => $successful ? 'succeeded' : 'failed',
                'last_bootstrap_output' => str($output !== '' ? $output : 'No output returned.')->limit(20000)->toString(),
                'last_bootstrap_ran_at' => now(),
            ]);
            $this->broadcastClusterUpdated($server);

            if (! $successful) {
                return;
            }

            $capabilities = collect($server->capabilities ?? [])
                ->push('coold')
                ->when($server->builder_enabled, fn ($capabilities) => $capabilities->push('builder'))
                ->unique()
                ->values()
                ->all();

            $server->update([
                'status' => 'installed',
                'capabilities' => $capabilities,
                'last_bootstrapped_at' => now(),
            ]);
            $this->broadcastClusterUpdated($server);

            $this->writeBootstrapMarker($cluster, $server, $sshConfigLocation);
        } catch (\Throwable $e) {
            $this->markFailed($server, $action, $e->getMessage());
        } finally {
            $this->deleteDirectory($tempDirectory);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $server = V5Server::query()->find($this->serverId);

        if (! $server instanceof V5Server) {
            return;
        }

        $this->markFailed($server, $server->last_bootstrap_action ?? 'bootstrap', $exception?->getMessage() ?? 'Bootstrap job failed.');

        Log::warning('V5 server bootstrap job failed', [
            'server_id' => $this->serverId,
            'cluster_id' => $this->clusterId,
            'exception' => $exception?->getMessage(),
        ]);
    }

    private function markFailed(V5Server $server, string $action, string $output): void
    {
        $server->update([
            'last_bootstrap_action' => $action,
            'last_bootstrap_status' => 'failed',
            'last_bootstrap_output' => str($output)->limit(20000)->toString(),
            'last_bootstrap_ran_at' => now(),
        ]);
        $this->broadcastClusterUpdated($server);
    }

    private function broadcastClusterUpdated(V5Server $server): void
    {
        V5ClusterUpdated::dispatch($server->team_id, $server->cluster_id);
    }

    private function bootstrapCommand(V5Cluster $cluster, Collection $servers, V5Server $newServer, string $sshConfigLocation, string $action): array
    {
        $command = [
            $this->coolifyCliBin(),
            'init',
            $action,
            '--format',
            'table',
            '--nodes',
            $servers->map(fn (V5Server $server) => $this->bootstrapNode($server))->implode(','),
            '--ssh-config',
            $sshConfigLocation,
            '--namespaces',
            implode(',', $cluster->namespaces ?? V5Cluster::DEFAULT_NAMESPACES),
            '--container-pool',
            $cluster->container_network_pool,
            '--container-prefix',
            (string) $cluster->container_network_prefix,
            '--wg-mgmt-pool',
            $cluster->wireguard_management_pool,
            '--wg-interface',
            $cluster->wireguard_interface,
            '--wg-listen-port',
            (string) $cluster->wireguard_listen_port,
            '--coold-version',
            $cluster->coold_version,
            '--corrosion-version',
            $cluster->corrosion_version,
            '--corrosion-gossip-port',
            (string) $cluster->corrosion_gossip_port,
            '--corrosion-api-port',
            (string) $cluster->corrosion_api_port,
        ];

        if ($action === 'extend') {
            array_push($command, '--new-nodes', $this->bootstrapNode($newServer));
        }

        $listenOverrides = $this->wireguardListenPortOverrides($servers);
        if ($listenOverrides !== '') {
            array_push($command, '--wg-listen-port-overrides', $listenOverrides);
        }

        $endpointOverrides = $this->wireguardEndpointOverrides($servers);
        if ($endpointOverrides !== '') {
            array_push($command, '--wg-endpoint-overrides', $endpointOverrides);
        }

        if (! $cluster->default_deny_containers) {
            $command[] = '--skip-default-deny';
        }

        $builderServers = $servers->filter(fn (V5Server $server) => $server->builder_enabled);
        if ($cluster->builder_enabled && $builderServers->isNotEmpty()) {
            array_push(
                $command,
                '--enable-builder',
                '--builder-hosts',
                $builderServers
                    ->map(fn (V5Server $server) => $this->bootstrapNode($server))
                    ->implode(','),
                '--builder-capacity',
                (string) $cluster->builder_capacity,
                '--builder-cpu-quota',
                $cluster->builder_cpu_quota,
                '--builder-memory-max',
                $cluster->builder_memory_max,
                '--builder-timeout-secs',
                (string) $cluster->builder_timeout_secs,
            );
        }

        $command[] = '--yes';

        return $command;
    }

    private function coolifyCliBin(): string
    {
        $configuredBinary = (string) config('coold.coolify_cli_bin', '/usr/local/bin/coolify');
        $devBinary = base_path('.dev/bin/coolify');

        if ($configuredBinary === '/usr/local/bin/coolify' && $this->isRunnableDevelopmentCliBinary($devBinary)) {
            return $devBinary;
        }

        return $configuredBinary;
    }

    private function isRunnableDevelopmentCliBinary(string $binary): bool
    {
        if (! is_file($binary)) {
            return false;
        }

        $header = file_get_contents($binary, false, null, 0, 4);

        if ($header === false) {
            return false;
        }

        if (str_starts_with($header, '#!')) {
            return true;
        }

        if ($header === "\x7FELF") {
            return true;
        }

        return false;
    }

    private function bootstrapNode(V5Server $server): string
    {
        return 'v5-server-'.($server->uuid ?: $server->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function detectExistingBootstrap(V5Server $server, string $sshConfigLocation): array
    {
        $result = Process::timeout(15)->run([
            'ssh',
            '-F',
            $sshConfigLocation,
            $this->bootstrapNode($server),
            'if [ -f '.escapeshellarg(self::BOOTSTRAP_MARKER_PATH).' ]; then cat '.escapeshellarg(self::BOOTSTRAP_MARKER_PATH).'; fi',
        ]);

        if (! $result->successful()) {
            return [];
        }

        $output = trim($result->output());

        if ($output === '') {
            return [];
        }

        try {
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $marker
     */
    private function adoptExistingBootstrap(V5Server $server, array $marker): void
    {
        $serverUuid = is_string($marker['server_uuid'] ?? null) ? $marker['server_uuid'] : null;
        $updates = [
            'wireguard_management_ip' => is_string($marker['wireguard_management_ip'] ?? null) ? $marker['wireguard_management_ip'] : $server->wireguard_management_ip,
            'wireguard_public_key' => is_string($marker['wireguard_public_key'] ?? null) ? $marker['wireguard_public_key'] : $server->wireguard_public_key,
            'container_subnets' => is_array($marker['container_subnets'] ?? null) ? $marker['container_subnets'] : $server->container_subnets,
            'status' => 'installed',
            'last_bootstrap_status' => 'succeeded',
            'last_bootstrap_output' => 'Adopted existing Coolify bootstrap state for this cluster.',
            'last_bootstrap_ran_at' => now(),
            'last_bootstrapped_at' => now(),
        ];

        if ($serverUuid !== null && ! V5Server::query()->where('uuid', $serverUuid)->whereKeyNot($server->id)->exists()) {
            $updates['uuid'] = $serverUuid;
        }

        $server->update($updates);
        $this->broadcastClusterUpdated($server);
    }

    private function writeBootstrapMarker(V5Cluster $cluster, V5Server $server, string $sshConfigLocation): void
    {
        $payload = base64_encode(json_encode([
            'cluster_id' => $cluster->id,
            'server_uuid' => $server->uuid,
            'wireguard_management_ip' => $server->wireguard_management_ip,
            'wireguard_public_key' => $server->wireguard_public_key,
            'container_subnets' => $server->container_subnets ?? [],
        ], JSON_THROW_ON_ERROR));

        Process::timeout(15)->run([
            'ssh',
            '-F',
            $sshConfigLocation,
            $this->bootstrapNode($server),
            "payload='{$payload}'; if [ \"$(id -u)\" = \"0\" ]; then mkdir -p /etc/coolify && printf %s \"$payload\" | base64 -d > ".escapeshellarg(self::BOOTSTRAP_MARKER_PATH)."; else sudo mkdir -p /etc/coolify && printf %s \"$payload\" | base64 -d | sudo tee ".escapeshellarg(self::BOOTSTRAP_MARKER_PATH).' >/dev/null; fi',
        ]);
    }

    /**
     * @param  Collection<int, V5Server>  $servers
     */
    private function writeBootstrapSshConfig(Collection $servers, string $tempDirectory): string
    {
        $config = '';

        $servers->each(function (V5Server $server) use (&$config, $tempDirectory): void {
            $keyLocation = "{$tempDirectory}/server-{$server->id}.key";
            file_put_contents($keyLocation, $server->privateKey->private_key);
            chmod($keyLocation, 0600);

            $config .= implode("\n", [
                'Host '.$this->bootstrapNode($server),
                '  HostName '.$server->host,
                '  Port '.$server->ssh_port,
                '  User '.$server->ssh_user,
                '  IdentityFile '.$keyLocation,
                '  IdentitiesOnly yes',
                '  LogLevel ERROR',
                '  StrictHostKeyChecking no',
                '  UserKnownHostsFile /dev/null',
                '  BatchMode yes',
                '',
            ]);
        });

        $sshConfigLocation = "{$tempDirectory}/ssh.config";
        file_put_contents($sshConfigLocation, $config);
        chmod($sshConfigLocation, 0600);

        return $sshConfigLocation;
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = "{$directory}/{$file}";

            if (is_dir($path)) {
                $this->deleteDirectory($path);

                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }

    /**
     * @param  Collection<int, V5Server>  $servers
     */
    private function wireguardListenPortOverrides(Collection $servers): string
    {
        return $servers
            ->filter(fn (V5Server $server) => $server->wireguard_listen_port_override !== null)
            ->map(fn (V5Server $server) => $this->bootstrapNode($server).'='.$server->wireguard_listen_port_override)
            ->implode(',');
    }

    /**
     * @param  Collection<int, V5Server>  $servers
     */
    private function wireguardEndpointOverrides(Collection $servers): string
    {
        return $servers
            ->filter(fn (V5Server $server) => $server->wireguard_endpoint_override !== null)
            ->map(fn (V5Server $server) => $this->bootstrapNode($server).'='.$server->wireguard_endpoint_override)
            ->implode(',');
    }
}
