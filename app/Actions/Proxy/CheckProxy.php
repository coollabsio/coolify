<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class CheckProxy
{
    use AsAction;

    // It should return if the proxy should be started (true) or not (false)
    public function handle(Server $server, $fromUI = false): bool
    {
        if (! $server->isFunctional()) {
            return false;
        }
        if ($server->isBuildServer()) {
            if ($server->proxy) {
                $server->proxy = null;
                $server->save();
            }

            return false;
        }
        $proxyType = $server->proxyType();
        if ((is_null($proxyType) || $proxyType === 'NONE' || $server->proxy->force_stop) && ! $fromUI) {
            return false;
        }
        if (! $server->isProxyShouldRun()) {
            if ($fromUI) {
                throw new \Exception('Proxy should not run. You selected the Custom Proxy.');
            } else {
                return false;
            }
        }

        // Determine proxy container name based on environment
        $proxyContainerName = $server->isSwarm() ? 'coolify-proxy_traefik' : 'coolify-proxy';

        if ($server->isSwarm()) {
            $status = getContainerStatus($server, $proxyContainerName);
            $server->proxy->set('status', $status);
            $server->save();
            if ($status === 'running') {
                return false;
            }

            return true;
        } else {
            $status = getContainerStatus($server, $proxyContainerName);
            if ($status === 'running') {
                $server->proxy->set('status', 'running');
                $server->save();

                return false;
            }
            if ($server->settings->is_cloudflare_tunnel) {
                return false;
            }
            $ip = $server->ip;
            if ($server->id === 0) {
                $ip = 'host.docker.internal';
            }
            $portsToCheck = ['80', '443'];

            try {
                if ($server->proxyType() !== ProxyTypes::NONE->value) {
                    $proxyCompose = CheckConfiguration::run($server);
                    if (isset($proxyCompose)) {
                        $yaml = Yaml::parse($proxyCompose);
                        $configPorts = [];
                        if ($server->proxyType() === ProxyTypes::TRAEFIK->value) {
                            $ports = data_get($yaml, 'services.traefik.ports');
                        } elseif ($server->proxyType() === ProxyTypes::CADDY->value) {
                            $ports = data_get($yaml, 'services.caddy.ports');
                        }
                        if (isset($ports)) {
                            foreach ($ports as $port) {
                                $configPorts[] = str($port)->before(':')->value();
                            }
                        }
                        // Combine default ports with config ports
                        $portsToCheck = array_merge($portsToCheck, $configPorts);
                    }
                } else {
                    $portsToCheck = [];
                }
            } catch (\Exception $e) {
                Log::error('Error checking proxy: '.$e->getMessage());
            }
            if (count($portsToCheck) === 0) {
                return false;
            }
            $portsToCheck = array_values(array_unique($portsToCheck));
            // Check port conflicts in parallel
            $conflicts = $this->checkPortConflictsInParallel($server, $portsToCheck, $proxyContainerName);
            foreach ($conflicts as $port => $conflict) {
                if ($conflict) {
                    if ($fromUI) {
                        throw new \Exception("Port $port is in use.<br>You must stop the process using this port.<br><br>Docs: <a target='_blank' class='dark:text-white hover:underline' href='https://coolify.io/docs'>https://coolify.io/docs</a><br>Discord: <a target='_blank' class='dark:text-white hover:underline' href='https://coolify.io/discord'>https://coolify.io/discord</a>");
                    } else {
                        return false;
                    }
                }
            }

            return true;
        }
    }

    /**
     * Check multiple ports for conflicts in parallel
     * Returns an array with port => conflict_status mapping
     */
    private function checkPortConflictsInParallel(Server $server, array $ports, string $proxyContainerName): array
    {
        if (empty($ports)) {
            return [];
        }

        try {
            // Build concurrent port check commands
            $results = Process::concurrently(function ($pool) use ($server, $ports, $proxyContainerName) {
                foreach ($ports as $port) {
                    $commands = $this->buildPortCheckCommands($server, $port, $proxyContainerName);
                    $pool->command($commands['ssh_command'])->timeout(10);
                }
            });

            // Process results
            $conflicts = [];

            foreach ($ports as $index => $port) {
                $result = $results[$index] ?? null;

                if ($result) {
                    $conflicts[$port] = $this->parsePortCheckResult($result, $port, $proxyContainerName);
                } else {
                    // If process failed, assume no conflict to avoid false positives
                    $conflicts[$port] = false;
                }
            }

            return $conflicts;
        } catch (\Throwable $e) {
            Log::warning('Parallel port checking failed: '.$e->getMessage().'. Falling back to sequential checking.');

            // Fallback to sequential checking if parallel fails
            $conflicts = [];
            foreach ($ports as $port) {
                $conflicts[$port] = $this->isPortConflict($server, $port, $proxyContainerName);
            }

            return $conflicts;
        }
    }

    /**
     * Build the SSH command for checking a specific port
     */
    private function buildPortCheckCommands(Server $server, string $port, string $proxyContainerName): array
    {
        // First check if our own proxy is using this port (which is fine)
        $getProxyContainerId = "docker ps -a --filter name=$proxyContainerName --format '{{.ID}}'";
        $checkProxyPortScript = "
            CONTAINER_ID=\$($getProxyContainerId);
            if [ ! -z \"\$CONTAINER_ID\" ]; then
                if docker inspect \$CONTAINER_ID --format '{{json .NetworkSettings.Ports}}' | grep -q '\"$port/tcp\"'; then
                    echo 'proxy_using_port';
                    exit 0;
                fi;
            fi;
        ";

        // Command sets for different ways to check ports, ordered by preference
        $portCheckScript = "
            $checkProxyPortScript
            
            # Try ss command first
            if command -v ss >/dev/null 2>&1; then
                ss_output=\$(ss -Htuln state listening sport = :$port 2>/dev/null);
                if [ -z \"\$ss_output\" ]; then
                    echo 'port_free';
                    exit 0;
                fi;
                count=\$(echo \"\$ss_output\" | grep -c ':$port ');
                if [ \$count -eq 0 ]; then
                    echo 'port_free';
                    exit 0;
                fi;
                # Check for dual-stack or docker processes
                if [ \$count -le 2 ] && (echo \"\$ss_output\" | grep -q 'docker\\|coolify'); then
                    echo 'port_free';
                    exit 0;
                fi;
                echo \"port_conflict|\$ss_output\";
                exit 0;
            fi;
            
            # Try netstat as fallback
            if command -v netstat >/dev/null 2>&1; then
                netstat_output=\$(netstat -tuln 2>/dev/null | grep ':$port ');
                if [ -z \"\$netstat_output\" ]; then
                    echo 'port_free';
                    exit 0;
                fi;
                count=\$(echo \"\$netstat_output\" | grep -c 'LISTEN');
                if [ \$count -eq 0 ]; then
                    echo 'port_free';
                    exit 0;
                fi;
                if [ \$count -le 2 ] && (echo \"\$netstat_output\" | grep -q 'docker\\|coolify'); then
                    echo 'port_free';
                    exit 0;
                fi;
                echo \"port_conflict|\$netstat_output\";
                exit 0;
            fi;
            
            # Final fallback using nc
            if nc -z -w1 127.0.0.1 $port >/dev/null 2>&1; then
                echo 'port_conflict|nc_detected';
            else
                echo 'port_free';
            fi;
        ";

        $sshCommand = \App\Helpers\SshMultiplexingHelper::generateSshCommand($server, $portCheckScript);

        return [
            'ssh_command' => $sshCommand,
            'script' => $portCheckScript,
        ];
    }

    /**
     * Parse the result from port check command
     */
    private function parsePortCheckResult($processResult, string $port, string $proxyContainerName): bool
    {
        $exitCode = $processResult->exitCode();
        $output = trim($processResult->output());
        $errorOutput = trim($processResult->errorOutput());

        if ($exitCode !== 0) {
            return false;
        }

        if ($output === 'proxy_using_port' || $output === 'port_free') {
            return false; // No conflict
        }

        if (str_starts_with($output, 'port_conflict|')) {
            $details = substr($output, strlen('port_conflict|'));

            // Additional logic to detect dual-stack scenarios
            if ($details !== 'nc_detected') {
                // Check for dual-stack scenario - typically 1-2 listeners (IPv4+IPv6)
                $lines = explode("\n", $details);
                if (count($lines) <= 2) {
                    // Look for IPv4 and IPv6 in the listing
                    if ((strpos($details, '0.0.0.0:'.$port) !== false && strpos($details, ':::'.$port) !== false) ||
                        (strpos($details, '*:'.$port) !== false && preg_match('/\*:'.$port.'.*IPv[46]/', $details))) {

                        return false; // This is just a normal dual-stack setup
                    }
                }
            }

            return true; // Real port conflict
        }

        return false;
    }

    /**
     * Smart port checker that handles dual-stack configurations
     * Returns true only if there's a real port conflict (not just dual-stack)
     */
    private function isPortConflict(Server $server, string $port, string $proxyContainerName): bool
    {
        // First check if our own proxy is using this port (which is fine)
        try {
            $getProxyContainerId = "docker ps -a --filter name=$proxyContainerName --format '{{.ID}}'";
            $containerId = trim(instant_remote_process([$getProxyContainerId], $server));

            if (! empty($containerId)) {
                $checkProxyPort = "docker inspect $containerId --format '{{json .NetworkSettings.Ports}}' | grep '\"$port/tcp\"'";
                try {
                    instant_remote_process([$checkProxyPort], $server);

                    // Our proxy is using the port, which is fine
                    return false;
                } catch (\Throwable $e) {
                    // Our container exists but not using this port
                }
            }
        } catch (\Throwable $e) {
            // Container not found or error checking, continue with regular checks
        }

        // Command sets for different ways to check ports, ordered by preference
        $commandSets = [
            // Set 1: Use ss to check listener counts by protocol stack
            [
                'available' => 'command -v ss >/dev/null 2>&1',
                'check' => [
                    // Get listening process details
                    "ss_output=\$(ss -Htuln state listening sport = :$port 2>/dev/null) && echo \"\$ss_output\"",
                    // Count IPv4 listeners
                    "echo \"\$ss_output\" | grep -c ':$port '",
                ],
            ],
            // Set 2: Use netstat as alternative to ss
            [
                'available' => 'command -v netstat >/dev/null 2>&1',
                'check' => [
                    // Get listening process details
                    "netstat_output=\$(netstat -tuln 2>/dev/null) && echo \"\$netstat_output\" | grep ':$port '",
                    // Count listeners
                    "echo \"\$netstat_output\" | grep ':$port ' | grep -c 'LISTEN'",
                ],
            ],
            // Set 3: Use lsof as last resort
            [
                'available' => 'command -v lsof >/dev/null 2>&1',
                'check' => [
                    // Get process using the port
                    "lsof -i :$port -P -n | grep 'LISTEN'",
                    // Count listeners
                    "lsof -i :$port -P -n | grep 'LISTEN' | wc -l",
                ],
            ],
        ];

        // Try each command set until we find one available
        foreach ($commandSets as $set) {
            try {
                // Check if the command is available
                instant_remote_process([$set['available']], $server);

                // Run the actual check commands
                $output = instant_remote_process($set['check'], $server, true);
                // Parse the output lines
                $lines = explode("\n", trim($output));
                // Get the detailed output and listener count
                $details = trim(implode("\n", array_slice($lines, 0, -1)));
                $count = intval(trim($lines[count($lines) - 1] ?? '0'));
                // If no listeners or empty result, port is free
                if ($count == 0 || empty($details)) {
                    return false;
                }

                // Try to detect if this is our coolify-proxy
                if (strpos($details, 'docker') !== false || strpos($details, $proxyContainerName) !== false) {
                    // It's likely our docker or proxy, which is fine
                    return false;
                }

                // Check for dual-stack scenario - typically 1-2 listeners (IPv4+IPv6)
                // If exactly 2 listeners and both have same port, likely dual-stack
                if ($count <= 2) {
                    // Check if it looks like a standard dual-stack setup
                    $isDualStack = false;

                    // Look for IPv4 and IPv6 in the listing (ss output format)
                    if (preg_match('/LISTEN.*:'.$port.'\s/', $details) &&
                        (preg_match('/\*:'.$port.'\s/', $details) ||
                         preg_match('/:::'.$port.'\s/', $details))) {
                        $isDualStack = true;
                    }

                    // For netstat format
                    if (strpos($details, '0.0.0.0:'.$port) !== false &&
                        strpos($details, ':::'.$port) !== false) {
                        $isDualStack = true;
                    }

                    // For lsof format (IPv4 and IPv6)
                    if (strpos($details, '*:'.$port) !== false &&
                        preg_match('/\*:'.$port.'.*IPv4/', $details) &&
                        preg_match('/\*:'.$port.'.*IPv6/', $details)) {
                        $isDualStack = true;
                    }

                    if ($isDualStack) {
                        return false; // This is just a normal dual-stack setup
                    }
                }

                // If we get here, it's likely a real port conflict
                return true;

            } catch (\Throwable $e) {
                // This command set failed, try the next one
                continue;
            }
        }

        // Fallback to simpler check if all above methods fail
        try {
            // Just try to bind to the port directly to see if it's available
            $checkCommand = "nc -z -w1 127.0.0.1 $port >/dev/null 2>&1 && echo 'in-use' || echo 'free'";
            $result = instant_remote_process([$checkCommand], $server, true);

            return trim($result) === 'in-use';
        } catch (\Throwable $e) {
            // If everything fails, assume the port is free to avoid false positives
            return false;
        }
    }
}
