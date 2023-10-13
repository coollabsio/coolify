<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Activitylog\Models\Activity;

class CheckProxy
{
    use AsAction;
    public function handle(Server $server)
    {
        if (!$server->isProxyShouldRun()) {
            throw new \Exception("Proxy should not run");
        }
        $status = getContainerStatus($server, 'coolify-proxy');
        if ($status === 'running') {
            $server->proxy->set('status', 'running');
            $server->save();
            return 'OK';
        }
        $ip = $server->ip;
        if ($server->id === 0) {
            $ip = 'host.docker.internal';
        }

        $connection = @fsockopen($ip, '80');
        $connection = @fsockopen($ip, '443');
        $port80 = is_resource($connection) && fclose($connection);
        $port443 = is_resource($connection) && fclose($connection);
        ray($ip);
        if ($port80) {
            throw new \Exception("Port 80 is in use.<br>You must stop the process using this port.<br>Docs: <a target='_blank' href='https://coolify.io/docs'>https://coolify.io/docs</a> <br> Discord: <a target='_blank'  href='https://coollabs.io/discord'>https://coollabs.io/discord</a>");
        }
        if ($port443) {
            throw new \Exception("Port 443 is in use.<br>You must stop the process using this port.<br>Docs: <a target='_blank' href='https://coolify.io/docs'>https://coolify.io/docs</a> <br> Discord: <a target='_blank'  href='https://coollabs.io/discord'>https://coollabs.io/discord</a>>");
        }
    }
}
