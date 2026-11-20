<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyTypes;
use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use App\Services\ProxyPortParser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Lorisleiva\Actions\Concerns\AsAction;

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

            $portsToCheck = [];
            $publishesOnlyUncheckedPorts = false;

            try {
                if ($server->proxyType() !== ProxyTypes::NONE->value) {
                    $proxyCompose = GetProxyConfiguration::run($server);
                    $portsToCheck = ProxyPortParser::fromConfiguration($proxyCompose);
                    $publishesOnlyUncheckedPorts = $portsToCheck === [] && ProxyPortParser::publishesOnlyUncheckedPorts($proxyCompose);
                }
            } catch (\Throwable $e) {
                Log::error('Error checking proxy: '.$e->getMessage());

                return false;
            }
            if (count($portsToCheck) === 0) {
                // Variable ports such as `${HTTP_PORT}:80` are only resolved by Compose on start.
                return $publishesOnlyUncheckedPorts;
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
                    $pool->command($commands['ssh_command'])->input($commands['input'])->timeout(10);
                }
            });

            // Process results
            $conflicts = [];

            foreach ($ports as $index => $port) {
                $result = $results[$index] ?? null;

                if ($result) {
                    $conflicts[$port] = $this->parsePortCheckResult($result);
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
    private function buildPortCheckCommands(Server $server, int $port, string $proxyContainerName): array
    {
        $portCheckScript = $this->buildPortCheckScript($port, $proxyContainerName);

        return [
            'ssh_command' => SshMultiplexingHelper::generateSshBatchCommand($server),
            'input' => SshMultiplexingHelper::sshBatchInput($portCheckScript),
            'script' => $portCheckScript,
        ];
    }

    private function buildPortCheckScript(int $port, string $proxyContainerName): string
    {

        $dockerPortPattern = escapeshellarg('"'.$port.'/tcp"');
        $socketPort = escapeshellarg(':'.$port);
        $portSuffixPattern = escapeshellarg(':'.$port.' ');
        $listenerInProcNet = $this->listenerInProcNetCommand($port);

        // First check if our own proxy is using this port (which is fine)
        $getProxyContainerId = "docker ps -a --filter name=$proxyContainerName --format '{{.ID}}'";
        $checkProxyPortScript = "
            CONTAINER_ID=\$($getProxyContainerId);
            if [ ! -z \"\$CONTAINER_ID\" ]; then
                if docker inspect \$CONTAINER_ID --format '{{json .NetworkSettings.Ports}}' | grep -q $dockerPortPattern; then
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
                ss_output=\$(ss -Htuln state listening sport = $socketPort 2>/dev/null);
                if [ -z \"\$ss_output\" ]; then
                    echo 'port_free';
                    exit 0;
                fi;
                count=\$(echo \"\$ss_output\" | grep -c $portSuffixPattern);
                if [ \$count -eq 0 ]; then
                    echo 'port_free';
                    exit 0;
                fi;
                echo \"port_conflict|\$ss_output\";
                exit 0;
            fi;
            
            # Try netstat as fallback
            if command -v netstat >/dev/null 2>&1; then
                netstat_output=\$(netstat -tuln 2>/dev/null | grep $portSuffixPattern);
                if [ -z \"\$netstat_output\" ]; then
                    echo 'port_free';
                    exit 0;
                fi;
                count=\$(echo \"\$netstat_output\" | grep -c 'LISTEN');
                if [ \$count -eq 0 ]; then
                    echo 'port_free';
                    exit 0;
                fi;
                echo \"port_conflict|\$netstat_output\";
                exit 0;
            fi;
            
            # Final fallback without tools: the kernel socket table
            if $listenerInProcNet; then
                echo 'port_conflict|proc_net_tcp';
            else
                echo 'port_free';
            fi;
        ";

        return $portCheckScript;
    }

    /**
     * Parse the result from port check command
     */
    private function parsePortCheckResult($processResult): bool
    {
        return $processResult->exitCode() === 0
            && str_starts_with(trim($processResult->output()), 'port_conflict|');
    }

    /**
     * Sequential fallback of the parallel port check.
     * Returns true when something other than our proxy container listens on the port.
     */
    private function isPortConflict(Server $server, int $port, string $proxyContainerName): bool
    {
        $dockerPortPattern = escapeshellarg('"'.$port.'/tcp"');
        $socketPort = escapeshellarg(':'.$port);
        $portSuffixPattern = escapeshellarg(':'.$port.' ');

        // First check if our own proxy is using this port (which is fine)
        try {
            $getProxyContainerId = "docker ps -a --filter name=$proxyContainerName --format '{{.ID}}'";
            $containerId = trim(instant_remote_process([$getProxyContainerId], $server));

            if (! empty($containerId)) {
                $checkProxyPort = "docker inspect $containerId --format '{{json .NetworkSettings.Ports}}' | grep $dockerPortPattern";
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
                    "ss_output=\$(ss -Htuln state listening sport = $socketPort 2>/dev/null) && echo \"\$ss_output\"",
                    // Count IPv4 listeners
                    "echo \"\$ss_output\" | grep -c $portSuffixPattern",
                ],
            ],
            // Set 2: Use netstat as alternative to ss
            [
                'available' => 'command -v netstat >/dev/null 2>&1',
                'check' => [
                    // Get listening process details
                    "netstat_output=\$(netstat -tuln 2>/dev/null) && echo \"\$netstat_output\" | grep $portSuffixPattern",
                    // Count listeners
                    "echo \"\$netstat_output\" | grep $portSuffixPattern | grep -c 'LISTEN'",
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

                // Any other listener blocks the proxy. Our own proxy container was already checked above.
                return true;

            } catch (\Throwable $e) {
                // This command set failed, try the next one
                continue;
            }
        }

        try {
            $checkCommand = $this->listenerInProcNetCommand($port)." && echo 'in-use' || echo 'free'";
            $result = instant_remote_process([$checkCommand], $server, true);

            return trim($result) === 'in-use';
        } catch (\Throwable $e) {
            // If everything fails, assume the port is free to avoid false positives
            return false;
        }
    }

    /**
     * Shell condition that is true when a TCP socket listens on the port on any address.
     * It reads the kernel socket table, which lists the sockets of all users and needs no tool or root.
     */
    private function listenerInProcNetCommand(int $port): string
    {
        $listenPattern = escapeshellarg(sprintf('^ *[0-9]+: [0-9A-F]+:%04X [0-9A-F]+:[0-9A-F]+ 0A ', $port));

        return "cat /proc/net/tcp /proc/net/tcp6 2>/dev/null | grep -qE $listenPattern";
    }
}
