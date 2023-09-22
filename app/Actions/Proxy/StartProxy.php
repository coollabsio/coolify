<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Activitylog\Models\Activity;

class StartProxy
{
    use AsAction;
    public function handle(Server $server, bool $async = true): Activity|string
    {
        $commands = collect([]);
        $proxyType = $server->proxyType();
        if ($proxyType === 'none') {
            return 'OK';
        }
        $proxy_path = get_proxy_path();
        $configuration = CheckConfiguration::run($server);
        if (!$configuration) {
            throw new \Exception("Configuration is not synced");
        }
        $docker_compose_yml_base64 = base64_encode($configuration);
        $server->proxy->last_applied_settings = Str::of($docker_compose_yml_base64)->pipe('md5')->value;
        $server->save();

        $commands = $commands->merge([
            "command -v lsof >/dev/null || echo '####### Installing lsof...'",
            "command -v lsof >/dev/null || apt-get update",
            "command -v lsof >/dev/null || apt install -y lsof",
            "command -v lsof >/dev/null || command -v fuser >/dev/null || apt install -y psmisc",
            "cd $proxy_path",
            "echo '####### Creating Docker Compose file...'",
            "echo '####### Pulling docker image...'",
            'docker compose pull || docker-compose pull',
            "echo '####### Stopping existing coolify-proxy...'",
            "docker compose down -v --remove-orphans > /dev/null 2>&1 || docker-compose down -v --remove-orphans > /dev/null 2>&1 || true",
            "command -v fuser >/dev/null || command -v lsof >/dev/null || echo '####### Could not kill existing processes listening on port 80 & 443. Please stop the process holding these ports...'",
            "command -v lsof >/dev/null && lsof -nt -i:80 | xargs -r kill -9 || true",
            "command -v lsof >/dev/null && lsof -nt -i:443 | xargs -r kill -9 || true",
            "command -v fuser >/dev/null && fuser -k 80/tcp || true",
            "command -v fuser >/dev/null && fuser -k 443/tcp || true",
            "systemctl disable nginx > /dev/null 2>&1 || true",
            "systemctl disable apache2 > /dev/null 2>&1 || true",
            "systemctl disable apache > /dev/null 2>&1 || true",
            "echo '####### Starting coolify-proxy...'",
            'docker compose up -d --remove-orphans || docker-compose up -d --remove-orphans',
            "echo '####### Proxy installed successfully...'"
        ]);
        $commands = $commands->merge(connectProxyToNetworks($server));
        if (!$async) {
            instant_remote_process($commands, $server);
            return 'OK';
        } else {
            $activity = remote_process($commands, $server);
            return $activity;
        }
    }
}
