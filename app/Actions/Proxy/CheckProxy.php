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
        if (is_null($proxyType) || $proxyType === 'NONE' || $server->proxy->force_stop) {
            return false;
        }
        if (! $server->isProxyShouldRun()) {
            if ($fromUI) {
                throw new \Exception('Proxy should not run. You selected the Custom Proxy.');
            } else {
                return false;
            }
        }
        if ($server->isSwarm()) {
            $status = getContainerStatus($server, 'coolify-proxy_traefik');
            $server->proxy->set('status', $status);
            $server->save();
            if ($status === 'running') {
                return false;
            }

            return true;
        } else {
            $status = getContainerStatus($server, 'coolify-proxy');
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
                // Try multiple methods to check port availability
                $commands = [
                    // Method 1: Check /proc/net/tcp directly (convert port to hex)
                    "cat /proc/net/tcp | grep -q '00000000:".str_pad(dechex($port), 4, '0', STR_PAD_LEFT)."'",
                    // Method 2: Use ss command (modern alternative to netstat)
                    "ss -tuln | grep -q ':$port '",
                    // Method 3: Use lsof if available
                    "lsof -i :$port >/dev/null 2>&1",
                    // Method 4: Use fuser if available
                    "fuser $port/tcp >/dev/null 2>&1",
                ];

                $portInUse = false;
                foreach ($commands as $command) {
                    try {
                        instant_remote_process([$command], $server);
                        $portInUse = true;
                        break;
                    } catch (\Throwable $e) {

                        continue;
                    }
                }
                if ($portInUse) {
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
}
