<?php

namespace App\Services\Remote;

use App\Models\Server;
use Illuminate\Support\Collection;

class RemoteProcessManager
{
    private Server $server;

    public function __construct(Server $server) {
        $this->server = $server;

    }


    public function execute(Collection|array|string $commands): string {
        $commands = $this->getCommandCollection($commands);

        $factory = new InstantRemoteProcessFactory($this->server);

        $output = $factory->getCommandOutput($commands);

        $process = new InstantRemoteProcess($this->server, $output);

        return $process->getOutput();
    }

    private function getCommandCollection(Collection|array|string $commands): Collection {
        if ($commands instanceof Collection) {
            return $commands;
        }

        if (is_array($commands)) {
            return collect($commands);
        }

        return collect([$commands]);
    }
}
