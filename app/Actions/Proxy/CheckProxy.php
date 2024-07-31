<?php

namespace App\Actions\Proxy;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class CheckProxy
{
    use AsAction;

    public function handle(Server $server, $fromUI = false)
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

            $connection80 = @fsockopen($ip, '80');
            $connection443 = @fsockopen($ip, '443');
            $port80 = is_resource($connection80) && fclose($connection80);
            $port443 = is_resource($connection443) && fclose($connection443);
            if ($port80) {
                if ($fromUI) {
                    throw new \Exception("Port 80 is in use.<br>You must stop the process using this port.<br>Docs: <a target='_blank' href='https://coolify.io/docs'>https://coolify.io/docs</a><br>Discord: <a target='_blank' href='https://coollabs.io/discord'>https://coollabs.io/discord</a>");
                } else {
                    return false;
                }
            }
            if ($port443) {
                if ($fromUI) {
                    throw new \Exception("Port 443 is in use.<br>You must stop the process using this port.<br>Docs: <a target='_blank' href='https://coolify.io/docs'>https://coolify.io/docs</a><br>Discord: <a target='_blank' href='https://coollabs.io/discord'>https://coollabs.io/discord</a>");
                } else {
                    return false;
                }
            }

            return true;
        }
    }
}
