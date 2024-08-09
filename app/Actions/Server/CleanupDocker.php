<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class CleanupDocker
{
    use AsAction;

    public function handle(Server $server, bool $force = true)
    {
        $commonCommands = [
            'docker container prune -f --filter "label=coolify.managed=true"',
            'docker image prune -f',
            'docker builder prune -f',
            'docker network prune -f',
        ];

        $forceCommands = [
            'docker container rm $(docker container ls -aq --filter status=exited --filter status=created)',
            'docker image prune -af',
            'docker builder prune -af',
            'docker system prune -af',
            'docker network prune -f',
        ];

        $additionalCommands = [
            'docker rmi $(docker images -f "dangling=true" -q)',
            'docker network rm $(docker network ls -q -f "unused=true")',
            'docker system prune -f',
        ];

        if ($force) {
            $commands = array_merge($forceCommands, $commonCommands, $additionalCommands);
            $commands[] = 'docker rm $(docker ps -a -q --filter status=exited --filter status=created)';
        } else {
            $commands = array_merge($commonCommands, $additionalCommands);
        }

        foreach ($commands as $command) {
            instant_remote_process([$command], $server, false);
        }
    }
}
