<?php

namespace App\Actions\Application;

use App\Models\Application;
use App\Models\Server;
use App\Services\DockerBuildCacheConfiguration;
use Lorisleiva\Actions\Concerns\AsAction;

final class CleanupDockerBuildCache
{
    use AsAction;

    public function handle(Application $application): void
    {
        $serverIds = $application->deployment_queue()
            ->whereNotNull('build_server_id')
            ->pluck('build_server_id')
            ->push(data_get($application, 'destination.server.id'))
            ->filter()
            ->unique();

        if ($serverIds->isEmpty()) {
            return;
        }

        $cachePath = DockerBuildCacheConfiguration::localCachePath($application->uuid);

        Server::query()->whereIn('id', $serverIds)->each(function (Server $server) use ($cachePath): void {
            instant_remote_process(
                ['rm -rf -- '.escapeshellarg($cachePath)],
                $server,
                false,
            );
        });
    }
}
