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
        ['uptime' => $uptime, 'error' => $error] = $server->validateConnection();
        if (! $uptime) {
            throw new \Exception($error);
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
            foreach ($portsToCheck as $port) {
                $connection = @fsockopen($ip, $port);
                if (is_resource($connection) && fclose($connection)) {
                    if ($fromUI) {
                        throw new \Exception("Port $port is in use.<br>You must stop the process using this port.<br>Docs: <a target='_blank' href='https://coolify.io/docs'>https://coolify.io/docs</a><br>Discord: <a target='_blank' href='https://coollabs.io/discord'>https://coollabs.io/discord</a>");
                    } else {
                        return false;
                    }
                }
            }

            return true;
        }
    }
}
