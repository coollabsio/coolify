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

        $commands = $this->getCommands();

        foreach ($commands as $command) {
            instant_remote_process([$command], $server, false);
        }
    }

    private function getCommands(): array
    {
        $settings = InstanceSettings::get();
        $helperImageVersion = data_get($settings, 'helper_version');
        $helperImage = config('coolify.helper_image');
        $helperImageWithVersion = config('coolify.helper_image').':'.$helperImageVersion;

        $commonCommands = [
            'docker container prune -f --filter "label=coolify.managed=true"',
            'docker image prune -af --filter "label!=coolify.managed=true"',
            'docker builder prune -af',
            "docker images --filter before=$helperImageWithVersion --filter reference=$helperImage | grep $helperImage | awk '{print $3}' | xargs -r docker rmi",
        ];

        return $commonCommands;
    }
}
