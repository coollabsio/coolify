<?php

namespace App\Actions\Server;

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
        $commonCommands = [
            'docker container prune -f --filter "label=coolify.managed=true"',
            'docker image prune -af',
            'docker builder prune -af',
        ];

        return $commonCommands;
    }
}
