<?php

namespace App\Actions\Server;

use App\Models\InstanceSettings;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class CleanupDocker
{
    use AsAction;

    public function handle(Server $server)
    {
        $settings = InstanceSettings::get();
        $helperImageVersion = data_get($settings, 'helper_version');
        $helperImage = config('coolify.helper_image');
        $helperImageWithVersion = "$helperImage:$helperImageVersion";

        $commands = [
            'docker container prune -f --filter "label=coolify.managed=true"',
            'docker image prune -af --filter "label!=coolify.managed=true"',
            'docker builder prune -af',
            "docker images --filter before=$helperImageWithVersion --filter reference=$helperImage | grep $helperImage | awk '{print \$3}' | xargs -r docker rmi",
        ];

        $serverSettings = $server->settings;
        if ($serverSettings->delete_unused_volumes) {
            $commands[] = 'docker volume prune -af';
        }

        if ($serverSettings->delete_unused_networks) {
            $commands[] = 'docker network prune -f';
        }

        foreach ($commands as $command) {
            instant_remote_process([$command], $server, false);
        }
    }
}
