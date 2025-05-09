<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Support\Facades\Log;
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

            foreach ($portsToCheck as $port) {
                // Use the smart port checker that handles dual-stack properly
                if ($this->isPortConflict($server, $port, $proxyContainerName)) {
                    if ($fromUI) {
                        throw new \Exception("Port $port is in use.<br>You must stop the process using this port.<br><br>Docs: <a target='_blank' class='dark:text-white hover:underline' href='https://coolify.io/docs'>https://coolify.io/docs</a><br>Discord: <a target='_blank' class='dark:text-white hover:underline' href='https://coolify.io/discord'>https://coolify.io/discord</a>");
                    } else {
                        return false;
                    }
                }
            }
            try {
                if ($server->proxyType() !== ProxyTypes::NONE->value) {
                    $proxyCompose = CheckConfiguration::run($server);
                    if (isset($proxyCompose)) {
                        $yaml = Yaml::parse($proxyCompose);
                        $portsToCheck = [];
                        if ($server->proxyType() === ProxyTypes::TRAEFIK->value) {
                            $ports = data_get($yaml, 'services.traefik.ports');
                        } elseif ($server->proxyType() === ProxyTypes::CADDY->value) {
                            $ports = data_get($yaml, 'services.caddy.ports');
                        }
                        if (isset($ports)) {
                            foreach ($ports as $port) {
                                $portsToCheck[] = str($port)->before(':')->value();
                            }
                        }
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

            return true;
        }
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
                $details = trim($lines[0] ?? '');
                $count = intval(trim($lines[1] ?? '0'));

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
