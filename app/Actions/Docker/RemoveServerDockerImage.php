<?php

namespace App\Actions\Docker;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class RemoveServerDockerImage
{
    use AsAction;

    public function handle(Server $server, string $imageId): string
    {
        if (! $server->isFunctional()) {
            throw new \Exception('Server is not functional.');
        }

        if (blank($imageId)) {
            throw new \Exception('Docker image ID is required.');
        }

        return instant_remote_process([
            'docker image rm '.escapeshellarg($imageId),
        ], $server);
    }
}
