<?php

namespace App\Jobs;

use App\Actions\V5\Proxy\StartCaddyIngress;
use App\Enums\V5\ServerStatus;
use App\Events\V5ClusterUpdated;
use App\Models\PrivateKey;
use App\Models\V5\Cluster as V5Cluster;
use App\Models\V5\Server as V5Server;
use App\Services\Flux\AgentTokenIssuer;
use App\Services\Flux\FluxClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class V5BootstrapServerJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const BOOTSTRAP_MARKER_PATH = '/etc/coolify/v5-node.json';

    public const TIMEOUT_SECONDS = 7200;

    public int $tries = 1;

    public int $timeout = self::TIMEOUT_SECONDS;

    /**
     * Second idempotency layer on top of the controller's DB bootstrap claim,
     * aligned with its running-claim window (TIMEOUT_SECONDS plus margin).
     */
    public int $uniqueFor = self::TIMEOUT_SECONDS + 300;

    public function __construct(public int $clusterId, public int $serverId) {}

    public function uniqueId(): string
    {
        return (string) $this->serverId;
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

        $started = V5Server::query()
            ->whereKey($server->id)
            ->where('last_bootstrap_status', 'queued')
            ->update([
                'last_bootstrap_action' => $action,
                'last_bootstrap_status' => 'running',
                'last_bootstrap_output' => "Starting Coolify CLI {$action} for {$server->name}...",
                'last_bootstrap_ran_at' => now(),
            ]);

        if ($started === 0) {
            return;
        }

        $server->refresh();

        if ($servers->contains(fn (V5Server $server) => ! $server->privateKey instanceof PrivateKey)) {
            $this->markFailed($server, $action, 'The new server and every already-bootstrapped server in this cluster must have a private key before extending the cluster.');

            return;
        }

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
                $markerClusterUuid = $existingBootstrap['cluster_uuid'] ?? null;

                if (
                    (string) $existingBootstrap['cluster_id'] !== (string) $cluster->id
                    || (is_string($markerClusterUuid) && $markerClusterUuid !== $cluster->uuid)
                ) {
                    $this->markFailed($server, $action, 'This server is already bootstrapped for another cluster. Reset the host bootstrap state before joining this cluster.');

                    return;
                }

                $this->adoptExistingBootstrap($cluster, $server, $existingBootstrap, $sshConfigLocation);

                return;
            }

            $result = Process::timeout(7200)
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

            $this->persistBootstrapAssignments($cluster, $server, $result->output(), $sshConfigLocation);
            $server->refresh();

            // Resolve the coold version once so the on-host marker and the
            // database row always agree.
            $cooldVersion = $this->bootstrappedCooldVersion($cluster, $result->output());

            $this->writeBootstrapMarker($cluster, $server, $sshConfigLocation, $cooldVersion);

            $this->enrollCooldIntoFlux($server, $sshConfigLocation);
            $this->waitForFluxHostConnection($server);

            $server->update([
                'status' => ServerStatus::Installed->value,
                'has_coold' => true,
                'coold_version' => $cooldVersion,
                'last_bootstrapped_at' => now(),
            ]);
            $this->broadcastClusterUpdated($server);

            if ($server->isIngress()) {
                StartCaddyIngress::run($server->fresh('privateKey'));
            }
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
            'json',
            '--nodes',
            $servers->map(fn (V5Server $server) => $this->bootstrapNode($server))->implode(','),
            '--ssh-config',
            $sshConfigLocation,
            '--ssh-user',
            $newServer->ssh_user,
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
    private function adoptExistingBootstrap(V5Cluster $cluster, V5Server $server, array $marker, string $sshConfigLocation): void
    {
        $bootstrapNode = $this->bootstrapNode($server);
        $serverUuid = is_string($marker['server_uuid'] ?? null) ? $marker['server_uuid'] : null;
        $updates = [
            'wireguard_management_ip' => is_string($marker['wireguard_management_ip'] ?? null) ? $marker['wireguard_management_ip'] : $server->wireguard_management_ip,
            'wireguard_public_key' => is_string($marker['wireguard_public_key'] ?? null) ? $marker['wireguard_public_key'] : $server->wireguard_public_key,
            'coold_version' => is_string($marker['coold_version'] ?? null) && trim($marker['coold_version']) !== '' ? trim($marker['coold_version']) : $cluster->coold_version,
            'container_subnets' => is_array($marker['container_subnets'] ?? null) ? $marker['container_subnets'] : $server->container_subnets,
            'has_coold' => true,
            'status' => ServerStatus::Installed->value,
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

        $this->enrollCooldIntoFlux($server->fresh(), $sshConfigLocation, $bootstrapNode);
        $this->waitForFluxHostConnection($server->fresh());

        if ($server->isIngress()) {
            StartCaddyIngress::run($server->fresh('privateKey'));
        }
    }

    private function persistBootstrapAssignments(V5Cluster $cluster, V5Server $server, string $output, string $sshConfigLocation): void
    {
        $verifiedNode = $this->verifiedBootstrapNode($output, $server);
        $wireguardManagementIp = is_array($verifiedNode) && is_string($verifiedNode['wireguard_ip'] ?? null)
            ? $verifiedNode['wireguard_ip']
            : null;
        $warnings = [];

        if (! is_string($wireguardManagementIp) || $wireguardManagementIp === '') {
            $wireguardManagementIp = $this->readWireguardManagementIp($cluster, $server, $sshConfigLocation, $warnings);
        }

        $wireguardPublicKey = $this->readWireguardPublicKey($cluster, $server, $sshConfigLocation, $warnings);
        $containerSubnets = $this->readContainerSubnets($cluster, $server, $sshConfigLocation, $warnings);
        $updates = [];

        if ($wireguardManagementIp !== null && $wireguardManagementIp !== '') {
            $updates['wireguard_management_ip'] = $wireguardManagementIp;

            if (! is_string($server->node_address) || $server->node_address === '' || $server->node_address === $server->host) {
                $updates['node_address'] = $wireguardManagementIp;
            }
        } else {
            $warnings[] = 'Warning: could not determine the WireGuard management IP from the CLI output.';
        }

        if ($wireguardPublicKey !== null && $wireguardPublicKey !== '') {
            $updates['wireguard_public_key'] = $wireguardPublicKey;
        }

        if ($containerSubnets !== []) {
            $updates['container_subnets'] = $containerSubnets;
        }

        if ($warnings !== []) {
            $updates['last_bootstrap_output'] = str(trim($server->last_bootstrap_output."\n".implode("\n", $warnings)))
                ->limit(20000)
                ->toString();
        }

        if ($updates !== []) {
            $server->update($updates);
        }
    }

    /**
     * @param  array<int, string>  $warnings
     */
    private function readWireguardManagementIp(V5Cluster $cluster, V5Server $server, string $sshConfigLocation, array &$warnings): ?string
    {
        $interface = escapeshellarg($cluster->wireguard_interface);
        $script = implode("\n", [
            "SUDO=''",
            'if [ "$(id -u)" != "0" ]; then SUDO=\'sudo\'; fi',
            "\$SUDO ip -4 -o addr show dev {$interface} | awk '{print \$4}' | cut -d/ -f1 | head -n1",
        ]);

        $result = Process::timeout(15)->run([
            'ssh',
            '-F',
            $sshConfigLocation,
            $this->bootstrapNode($server),
            $script,
        ]);

        $ipAddress = trim($result->output());

        if (! $result->successful() || filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $warnings[] = 'Warning: could not read the WireGuard management IP from the server.';

            return null;
        }

        return $ipAddress;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function verifiedBootstrapNode(string $output, V5Server $server): ?array
    {
        $decoded = $this->decodedBootstrapOutput($output);

        if (! is_array($decoded)) {
            return null;
        }

        $verifiedNodes = data_get($decoded, 'verified');

        if (! is_array($verifiedNodes)) {
            return null;
        }

        $bootstrapNode = $this->bootstrapNode($server);

        foreach ($verifiedNodes as $verifiedNode) {
            if (! is_array($verifiedNode)) {
                continue;
            }

            $host = $verifiedNode['host'] ?? $verifiedNode['node'] ?? $verifiedNode['name'] ?? null;

            if ($host === $bootstrapNode || $host === $server->uuid || $host === $server->name || $host === $server->host) {
                return $verifiedNode;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodedBootstrapOutput(string $output): ?array
    {
        $output = trim($output);

        if ($output === '' || ! str_starts_with($output, '{')) {
            return null;
        }

        try {
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The CLI init JSON output does not currently report the installed coold
     * version, so fall back to the version the cluster asked the CLI to
     * install (`--coold-version`). If a future CLI adds a `coold_version` key
     * to its JSON output, prefer that.
     */
    private function bootstrappedCooldVersion(V5Cluster $cluster, string $output): ?string
    {
        $reported = data_get($this->decodedBootstrapOutput($output), 'coold_version');

        if (is_string($reported) && trim($reported) !== '') {
            return trim($reported);
        }

        return $cluster->coold_version;
    }

    /**
     * @param  array<int, string>  $warnings
     */
    private function readWireguardPublicKey(V5Cluster $cluster, V5Server $server, string $sshConfigLocation, array &$warnings): ?string
    {
        $interface = escapeshellarg($cluster->wireguard_interface);
        $script = implode("\n", [
            "SUDO=''",
            'if [ "$(id -u)" != "0" ]; then SUDO=\'sudo\'; fi',
            "\$SUDO wg show {$interface} public-key",
        ]);

        $result = Process::timeout(15)->run([
            'ssh',
            '-F',
            $sshConfigLocation,
            $this->bootstrapNode($server),
            $script,
        ]);

        $publicKey = trim($result->output());

        if (! $result->successful() || $publicKey === '') {
            $warnings[] = 'Warning: could not read the WireGuard public key from the server.';

            return null;
        }

        return $publicKey;
    }

    /**
     * The container subnets are allocated by the coolify CLI on the host; the podman
     * networks it creates are the source of truth, so read them back instead of
     * re-deriving the allocation locally.
     *
     * @param  array<int, string>  $warnings
     * @return array<string, string>
     */
    private function readContainerSubnets(V5Cluster $cluster, V5Server $server, string $sshConfigLocation, array &$warnings): array
    {
        $namespaces = $cluster->namespaces ?? V5Cluster::DEFAULT_NAMESPACES;

        if ($namespaces === []) {
            return [];
        }

        $namespaceArguments = collect($namespaces)
            ->map(fn (string $namespace): string => escapeshellarg($namespace))
            ->implode(' ');
        $script = implode("\n", [
            "SUDO=''",
            'if [ "$(id -u)" != "0" ]; then SUDO=\'sudo\'; fi',
            "for ns in {$namespaceArguments}; do",
            '  printf \'%s=\' "$ns"',
            '  $SUDO podman network inspect "coolify-${ns}-mesh" --format \'{{range .Subnets}}{{.Subnet}}{{end}}\' 2>/dev/null || true',
            '  printf \'\n\'',
            'done',
        ]);

        $result = Process::timeout(30)->run([
            'ssh',
            '-F',
            $sshConfigLocation,
            $this->bootstrapNode($server),
            $script,
        ]);

        if (! $result->successful()) {
            $warnings[] = 'Warning: could not read the container subnets from the server.';

            return [];
        }

        $subnets = [];

        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            [$namespace, $subnet] = array_pad(explode('=', trim($line), 2), 2, null);

            if (! is_string($namespace) || ! in_array($namespace, $namespaces, true) || ! $this->isIpv4Cidr($subnet)) {
                continue;
            }

            $subnets[$namespace] = $subnet;
        }

        if (count($subnets) !== count($namespaces)) {
            $warnings[] = 'Warning: could not read every container subnet from the server; the stored subnets may be incomplete.';
        }

        return $subnets;
    }

    private function isIpv4Cidr(?string $value): bool
    {
        if (! is_string($value) || ! str_contains($value, '/')) {
            return false;
        }

        [$ip, $prefix] = explode('/', $value, 2);

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && ctype_digit($prefix)
            && (int) $prefix <= 32;
    }

    private function enrollCooldIntoFlux(V5Server $server, string $sshConfigLocation, ?string $bootstrapNode = null): void
    {
        $fluxUrl = trim((string) config('coold.flux_url', ''));

        if ($fluxUrl === '') {
            throw new \RuntimeException('COOLIFY_COOLD_FLUX_URL is not configured, so the server cannot be enrolled into Flux. Set it and retry the bootstrap.');
        }

        $jwtPath = trim((string) config('coold.flux_host_jwt_path', '/etc/coolify/host-jwt'));

        if ($jwtPath === '') {
            $jwtPath = '/etc/coolify/host-jwt';
        }

        $fluxUrl = str_replace(["\r", "\n"], '', $fluxUrl);
        $jwtPath = str_replace(["\r", "\n"], '', $jwtPath);
        $hostId = $server->fluxHostId();
        $token = app(AgentTokenIssuer::class)->issueForServer($server);
        $tokenArgument = $this->shellArg($token);
        $hostId = str_replace(["\r", "\n"], '', $hostId);
        $jwtPathArgument = $this->shellPathArg($jwtPath);
        $dropInDirectory = '/etc/systemd/system/coold.service.d';
        $dropInPath = "{$dropInDirectory}/10-flux.conf";
        $script = <<<SH
set -e
SUDO=''
if [ "\$(id -u)" != "0" ]; then SUDO='sudo'; fi
\$SUDO mkdir -p /etc/coolify {$dropInDirectory}
printf %s {$tokenArgument} | \$SUDO tee {$jwtPathArgument} >/dev/null
\$SUDO chmod 600 {$jwtPathArgument}
cat <<'COOLIFY_FLUX_ENV' | \$SUDO tee {$dropInPath} >/dev/null
[Service]
Environment=COOLIFY_COOLD_FLUX_URL={$fluxUrl}
Environment=COOLIFY_COOLD_HOST_ID={$hostId}
Environment=COOLIFY_COOLD_HOST_JWT_PATH={$jwtPath}
COOLIFY_FLUX_ENV
\$SUDO systemctl daemon-reload
\$SUDO systemctl restart coold.service
SH;

        $result = Process::timeout(60)->run([
            'ssh',
            '-F',
            $sshConfigLocation,
            $bootstrapNode ?? $this->bootstrapNode($server),
            $script,
        ]);

        if (! $result->successful()) {
            $output = trim($result->output()."\n".$result->errorOutput());

            throw new \RuntimeException(
                ($output !== '' ? $output : 'Could not enroll coold into Flux.')
                ."\nThe WireGuard mesh was created successfully; retrying this bootstrap is safe and will resume from Flux enrollment."
            );
        }
    }

    private function waitForFluxHostConnection(V5Server $server): void
    {
        $timeoutSeconds = (int) config('flux.bootstrap_host_connection_timeout_seconds', 30);

        if ($timeoutSeconds <= 0) {
            return;
        }

        $hostId = $server->fluxHostId();

        if (! is_string($hostId) || $hostId === '') {
            throw new \RuntimeException('Server is missing its Flux host id after bootstrap.');
        }

        $deadline = time() + $timeoutSeconds;
        $lastError = null;

        do {
            try {
                app(FluxClient::class)->cooldLogs($hostId, 1);

                return;
            } catch (\Throwable $exception) {
                $lastError = $exception->getMessage();
                sleep(1);
            }
        } while (time() < $deadline);

        throw new \RuntimeException(
            'The server was bootstrapped, but coold did not connect to Flux in time. '
            .'Wait a moment and retry the bootstrap before deploying applications.'
            .($lastError !== null ? " Last Flux error: {$lastError}" : '')
        );
    }

    private function shellArg(string $value): string
    {
        return escapeshellarg($value);
    }

    private function shellPathArg(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_\/:.,@%+=-]+$/', $value) === 1) {
            return $value;
        }

        return $this->shellArg($value);
    }

    private function writeBootstrapMarker(V5Cluster $cluster, V5Server $server, string $sshConfigLocation, ?string $cooldVersion = null): void
    {
        $payload = base64_encode(json_encode([
            'cluster_id' => $cluster->id,
            'cluster_uuid' => $cluster->uuid,
            'server_uuid' => $server->uuid,
            'wireguard_management_ip' => $server->wireguard_management_ip,
            'wireguard_public_key' => $server->wireguard_public_key,
            'coold_version' => $cooldVersion ?? $server->coold_version ?? $cluster->coold_version,
            'container_subnets' => $server->container_subnets ?? [],
        ], JSON_THROW_ON_ERROR));

        $result = Process::timeout(15)->run([
            'ssh',
            '-F',
            $sshConfigLocation,
            $this->bootstrapNode($server),
            "payload='{$payload}'; if [ \"$(id -u)\" = \"0\" ]; then mkdir -p /etc/coolify && printf %s \"$payload\" | base64 -d > ".escapeshellarg(self::BOOTSTRAP_MARKER_PATH)."; else sudo mkdir -p /etc/coolify && printf %s \"$payload\" | base64 -d | sudo tee ".escapeshellarg(self::BOOTSTRAP_MARKER_PATH).' >/dev/null; fi',
        ]);

        if (! $result->successful()) {
            $output = trim($result->output()."\n".$result->errorOutput());

            throw new \RuntimeException('Could not write the bootstrap marker to the server: '.($output !== '' ? $output : 'the SSH command failed.'));
        }
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
