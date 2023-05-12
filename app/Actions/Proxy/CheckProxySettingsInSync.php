<?php

namespace App\Actions\Proxy;

use App\Enums\ActivityTypes;
use App\Enums\ProxyTypes;
use App\Models\Server;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Yaml\Yaml;

class CheckProxySettingsInSync
{
    public function __invoke(Server $server)
    {
        // @TODO What is the mechanism to make sure setting in sync?
        $folder_name = match ($server->extra_attributes->proxy) {
            ProxyTypes::TRAEFIK_V2->value => 'proxy',
        };

        $container_name = 'coolify-proxy';

        $output = instantRemoteProcess([
            // Folder exists, in ~/projects/<folder-name>
            'if [ -d "projects/'.$folder_name.'" ]; then echo "true"; else echo "false"; fi',
            // Container of name <container-name> is running
            <<<EOT
            [[ "$(docker inspect -f '{{.State.Running}}' $container_name 2>/dev/null)" == "true" ]] && echo "true" || echo "false"
            EOT,
        ], $server);

        return collect(
            explode(PHP_EOL, $output)
        )->every(fn($output) => $output === 'true');
    }
}
