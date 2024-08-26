<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class CleanupDocker
{
    use AsAction;

    public function handle(Server $server)
    {

        $commands = $this->getCommands($force);

        foreach ($commands as $command) {
            instant_remote_process([$command], $server, false);
        }
    }

    private function getCommands(bool $force): array
    {
        $commonCommands = [
            'docker container prune -f --filter "label=coolify.managed=true"',
            'docker image prune -f',
            'docker builder prune -f',
        ];

        if ($force) {
            return array_merge([
                'docker container prune -f --filter "label=coolify.managed=true"',
                'docker image prune -af',
                'docker builder prune -af',
            ], $commonCommands);
        }

        return $commonCommands;
    }
}
