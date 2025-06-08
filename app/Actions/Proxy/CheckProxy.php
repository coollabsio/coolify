<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyTypes;
use App\Events\ProxyStatusChanged;
use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class CheckProxy
{
    use AsAction;

    /**
     * Determine if the proxy should be started
     *
     * @param  bool  $fromUI  Whether this check is initiated from the UI
     * @return bool True if proxy should be started, false otherwise
     */
    public function handle(Server $server, bool $fromUI = false): bool
    {
        // Early validation checks
        if (! $this->shouldProxyRun($server, $fromUI)) {
            return false;
        }

        // Handle swarm vs standalone differently
        if ($server->isSwarm()) {
            return $this->checkSwarmProxy($server);
        }

        return $this->checkStandaloneProxy($server, $fromUI);
    }

    /**
     * Basic validation to determine if proxy can run at all
     */
    private function shouldProxyRun(Server $server, bool $fromUI): bool
    {
        // Server must be functional
        if (! $server->isFunctional()) {
            return false;
        }

        // Build servers don't need proxy
        if ($server->isBuildServer()) {
            if ($server->proxy) {
                $server->proxy = null;
                $server->save();
            }

            return false;
        }

        $proxyType = $server->proxyType();

        // Check if proxy is disabled or force stopped
        if ((is_null($proxyType) || $proxyType === 'NONE' || $server->proxy->force_stop) && ! $fromUI) {
            return false;
        }

        // Validate proxy configuration
        if (! $server->isProxyShouldRun()) {
            if ($fromUI) {
                throw new \Exception('Proxy should not run. You selected the Custom Proxy.');
            }

            return false;
        }

        return true;
    }

    /**
     * Check proxy status for swarm mode
     */
    private function checkSwarmProxy(Server $server): bool
    {
        $proxyContainerName = 'coolify-proxy_traefik';
        $status = getContainerStatus($server, $proxyContainerName);

        // Update status if changed
        if ($server->proxy->status !== $status) {
            $server->proxy->set('status', $status);
            $server->save();
        }

        // If running, no need to start
        return $status !== 'running';
    }

    /**
     * Check proxy status for standalone mode
     */
    private function checkStandaloneProxy(Server $server, bool $fromUI): bool
    {
        $proxyContainerName = 'coolify-proxy';
        $status = getContainerStatus($server, $proxyContainerName);

        if ($server->proxy->status !== $status) {
            $server->proxy->set('status', $status);
            $server->save();
            ProxyStatusChanged::dispatch($server->id);
        }

        // If already running, no need to start
        if ($status === 'running') {
            return false;
        }

        // Cloudflare tunnel doesn't need proxy
        if ($server->settings->is_cloudflare_tunnel) {
            return false;
        }

        // Check for port conflicts
        $portsToCheck = $this->getPortsToCheck($server);

        if (empty($portsToCheck)) {
            return false;
        }

        $this->validatePortsAvailable($server, $portsToCheck, $proxyContainerName, $fromUI);

        return true;
    }

    /**
     * Get list of ports that need to be available for the proxy
     */
    private function getPortsToCheck(Server $server): array
    {
        $defaultPorts = ['80', '443'];

        try {
            if ($server->proxyType() === ProxyTypes::NONE->value) {
                return [];
            }

            $proxyCompose = CheckConfiguration::run($server);
            if (! $proxyCompose) {
                return $defaultPorts;
            }

            $yaml = Yaml::parse($proxyCompose);
            $ports = $this->extractPortsFromCompose($yaml, $server->proxyType());

            return ! empty($ports) ? $ports : $defaultPorts;

        } catch (\Exception $e) {
            Log::error('Error checking proxy configuration: '.$e->getMessage());

            return $defaultPorts;
        }
    }

    /**
     * Extract ports from docker-compose configuration
     */
    private function extractPortsFromCompose(array $yaml, string $proxyType): array
    {
        $ports = [];
        $servicePorts = null;

        if ($proxyType === ProxyTypes::TRAEFIK->value) {
            $servicePorts = data_get($yaml, 'services.traefik.ports');
        } elseif ($proxyType === ProxyTypes::CADDY->value) {
            $servicePorts = data_get($yaml, 'services.caddy.ports');
        }

        if ($servicePorts) {
            foreach ($servicePorts as $port) {
                $ports[] = str($port)->before(':')->value();
            }
        }

        return array_unique($ports);
    }

    /**
     * Validate that required ports are available (checked in parallel)
     */
    private function validatePortsAvailable(Server $server, array $ports, string $proxyContainerName, bool $fromUI): void
    {
        if (empty($ports)) {
            return;
        }

        \Log::info('Checking ports in parallel: '.implode(', ', $ports));

        // Check all ports in parallel
        $conflicts = $this->checkPortsInParallel($server, $ports, $proxyContainerName);

        // Handle any conflicts found
        foreach ($conflicts as $port) {
            $message = "Port $port is in use";
            if ($fromUI) {
                $message = "Port $port is in use.<br>You must stop the process using this port.<br><br>".
                          "Docs: <a target='_blank' class='dark:text-white hover:underline' href='https://coolify.io/docs'>https://coolify.io/docs</a><br>".
                          "Discord: <a target='_blank' class='dark:text-white hover:underline' href='https://coolify.io/discord'>https://coolify.io/discord</a>";
            }
            throw new \Exception($message);
        }
    }

    /**
     * Check multiple ports for conflicts in parallel using concurrent processes
     */
    private function checkPortsInParallel(Server $server, array $ports, string $proxyContainerName): array
    {
        $conflicts = [];

        // First, quickly filter out ports used by our own proxy
        $ourProxyPorts = $this->getOurProxyPorts($server, $proxyContainerName);
        $portsToCheck = array_diff($ports, $ourProxyPorts);

        if (empty($portsToCheck)) {
            \Log::info('All ports are used by our proxy, no conflicts to check');

            return [];
        }

        // Build a single optimized command to check all ports at once
        $command = $this->buildBatchPortCheckCommand($portsToCheck, $server);
        if (! $command) {
            \Log::warning('No suitable port checking method available, falling back to sequential');

            return $this->checkPortsSequentially($server, $portsToCheck, $proxyContainerName);
        }

        try {
            $output = instant_remote_process([$command], $server, true);
            $results = $this->parseBatchPortCheckOutput($output, $portsToCheck);

            foreach ($results as $port => $isConflict) {
                if ($isConflict) {
                    $conflicts[] = $port;
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('Batch port check failed, falling back to sequential: '.$e->getMessage());

            return $this->checkPortsSequentially($server, $portsToCheck, $proxyContainerName);
        }

        return $conflicts;
    }

    /**
     * Get ports currently used by our proxy container
     */
    private function getOurProxyPorts(Server $server, string $proxyContainerName): array
    {
        try {
            $getProxyContainerId = "docker ps -a --filter name=$proxyContainerName --format '{{.ID}}'";
            $containerId = trim(instant_remote_process([$getProxyContainerId], $server));

            if (empty($containerId)) {
                return [];
            }

            $getPortsCommand = "docker inspect $containerId --format '{{json .NetworkSettings.Ports}}' 2>/dev/null || echo '{}'";
            $portsJson = instant_remote_process([$getPortsCommand], $server, true);
            $portsData = json_decode($portsJson, true);

            $ports = [];
            if (is_array($portsData)) {
                foreach (array_keys($portsData) as $portSpec) {
                    if (preg_match('/^(\d+)\/tcp$/', $portSpec, $matches)) {
                        $ports[] = $matches[1];
                    }
                }
            }

            return $ports;
        } catch (\Throwable $e) {
            \Log::warning('Failed to get proxy ports: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Build a single command to check multiple ports efficiently
     */
    private function buildBatchPortCheckCommand(array $ports, Server $server): ?string
    {
        $portList = implode('|', $ports);

        // Try different commands in order of preference
        $commands = [
            // ss - fastest and most reliable
            [
                'check' => 'command -v ss >/dev/null 2>&1',
                'exec' => "ss -Htuln state listening | grep -E ':($portList) ' | awk '{print \$5}' | cut -d: -f2 | sort -u",
            ],
            // netstat - widely available
            [
                'check' => 'command -v netstat >/dev/null 2>&1',
                'exec' => "netstat -tuln | grep -E ':($portList) ' | grep LISTEN | awk '{print \$4}' | cut -d: -f2 | sort -u",
            ],
            // lsof - last resort
            [
                'check' => 'command -v lsof >/dev/null 2>&1',
                'exec' => "lsof -iTCP -sTCP:LISTEN -P -n | grep -E ':($portList) ' | awk '{print \$9}' | cut -d: -f2 | sort -u",
            ],
        ];

        foreach ($commands as $cmd) {
            try {
                instant_remote_process([$cmd['check']], $server);

                return $cmd['exec'];
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }

    /**
     * Parse output from batch port check command
     */
    private function parseBatchPortCheckOutput(string $output, array $portsToCheck): array
    {
        $results = [];
        $usedPorts = array_filter(explode("\n", trim($output)));

        foreach ($portsToCheck as $port) {
            $results[$port] = in_array($port, $usedPorts);
        }

        return $results;
    }

    /**
     * Fallback to sequential checking if parallel fails
     */
    private function checkPortsSequentially(Server $server, array $ports, string $proxyContainerName): array
    {
        $conflicts = [];

        foreach ($ports as $port) {
            try {
                if ($this->isPortConflict($server, $port, $proxyContainerName)) {
                    $conflicts[] = $port;
                }
            } catch (\Throwable $e) {
                \Log::warning("Sequential port check failed for port $port: ".$e->getMessage());
                // Continue checking other ports
            }
        }

        return $conflicts;
    }

    /**
     * Smart port checker that handles dual-stack configurations
     * Returns true only if there's a real port conflict (not just dual-stack)
     */
    private function isPortConflict(Server $server, string $port, string $proxyContainerName): bool
    {
        // Check if our own proxy is using this port (which is fine)
        if ($this->isOurProxyUsingPort($server, $port, $proxyContainerName)) {
            return false;
        }

        // Try different methods to detect port conflicts
        $portCheckMethods = [
            'checkWithSs',
            'checkWithNetstat',
            'checkWithLsof',
            'checkWithNetcat',
        ];

        foreach ($portCheckMethods as $method) {
            try {
                $result = $this->$method($server, $port);
                if ($result !== null) {
                    return $result;
                }
            } catch (\Throwable $e) {
                continue; // Try next method
            }
        }

        // If all methods fail, assume port is free to avoid false positives
        return false;
    }

    /**
     * Check if our own proxy container is using the port
     */
    private function isOurProxyUsingPort(Server $server, string $port, string $proxyContainerName): bool
    {
        try {
            $getProxyContainerId = "docker ps -a --filter name=$proxyContainerName --format '{{.ID}}'";
            $containerId = trim(instant_remote_process([$getProxyContainerId], $server));

            if (empty($containerId)) {
                return false;
            }

            $checkProxyPort = "docker inspect $containerId --format '{{json .NetworkSettings.Ports}}' | grep '\"$port/tcp\"'";
            instant_remote_process([$checkProxyPort], $server);

            return true; // Our proxy is using the port
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Check port usage with ss command
     */
    private function checkWithSs(Server $server, string $port): ?bool
    {
        $commands = [
            'command -v ss >/dev/null 2>&1',
            "ss_output=\$(ss -Htuln state listening sport = :$port 2>/dev/null) && echo \"\$ss_output\"",
            "echo \"\$ss_output\" | grep -c ':$port '",
        ];

        $output = instant_remote_process($commands, $server, true);

        return $this->parsePortCheckOutput($output, $port);
    }

    /**
     * Check port usage with netstat command
     */
    private function checkWithNetstat(Server $server, string $port): ?bool
    {
        $commands = [
            'command -v netstat >/dev/null 2>&1',
            "netstat_output=\$(netstat -tuln 2>/dev/null) && echo \"\$netstat_output\" | grep ':$port '",
            "echo \"\$netstat_output\" | grep ':$port ' | grep -c 'LISTEN'",
        ];

        $output = instant_remote_process($commands, $server, true);

        return $this->parsePortCheckOutput($output, $port);
    }

    /**
     * Check port usage with lsof command
     */
    private function checkWithLsof(Server $server, string $port): ?bool
    {
        $commands = [
            'command -v lsof >/dev/null 2>&1',
            "lsof -i :$port -P -n | grep 'LISTEN'",
            "lsof -i :$port -P -n | grep 'LISTEN' | wc -l",
        ];

        $output = instant_remote_process($commands, $server, true);

        return $this->parsePortCheckOutput($output, $port);
    }

    /**
     * Check port usage with netcat as fallback
     */
    private function checkWithNetcat(Server $server, string $port): ?bool
    {
        $checkCommand = "nc -z -w1 127.0.0.1 $port >/dev/null 2>&1 && echo 'in-use' || echo 'free'";
        $result = instant_remote_process([$checkCommand], $server, true);

        return trim($result) === 'in-use';
    }

    /**
     * Parse output from port checking commands
     */
    private function parsePortCheckOutput(string $output, string $port): ?bool
    {
        $lines = explode("\n", trim($output));
        $details = trim($lines[0] ?? '');
        $count = intval(trim($lines[1] ?? '0'));

        // No listeners found
        if ($count === 0 || empty($details)) {
            return false;
        }

        // Check if it's likely our docker/proxy process
        if (strpos($details, 'docker') !== false || strpos($details, 'coolify-proxy') !== false) {
            return false;
        }

        // Handle dual-stack scenarios (1-2 listeners for IPv4+IPv6)
        if ($count <= 2 && $this->isDualStackSetup($details, $port)) {
            return false;
        }

        // Real port conflict detected
        return true;
    }

    /**
     * Detect if this is a normal dual-stack setup (IPv4 + IPv6)
     */
    private function isDualStackSetup(string $details, string $port): bool
    {
        $patterns = [
            // ss output format
            '/LISTEN.*:'.$port.'\s/',
            '/\*:'.$port.'\s/',
            '/:::'.$port.'\s/',
            // netstat format
            '/0\.0\.0\.0:'.$port.'/',
            '/:::'.$port.'/',
            // lsof format
            '/\*:'.$port.'.*IPv[46]/',
        ];

        $matches = 0;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $details)) {
                $matches++;
            }
        }

        // If we see patterns indicating both IPv4 and IPv6, it's likely dual-stack
        return $matches >= 2;
    }
}
