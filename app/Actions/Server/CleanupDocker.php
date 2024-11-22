<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class CleanupDocker
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(Server $server)
    {
        $settings = instanceSettings();
        $helperImageVersion = data_get($settings, 'helper_version');
        $helperImage = config('constants.coolify.helper_image');
        $helperImageWithVersion = "$helperImage:$helperImageVersion";

        $commands = [
            'docker container prune -f --filter "label=coolify.managed=true" --filter "label!=coolify.proxy=true"',
            'docker image prune -af --filter "label!=coolify.managed=true"',
            'docker builder prune -af',
            "docker images --filter before=$helperImageWithVersion --filter reference=$helperImage | grep $helperImage | awk '{print $3}' | xargs -r docker rmi -f",
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
