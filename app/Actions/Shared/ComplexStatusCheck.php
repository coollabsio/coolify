<?php

namespace App\Actions\Shared;

use App\Models\Application;
use Lorisleiva\Actions\Concerns\AsAction;

class ComplexStatusCheck
{
    use AsAction;

    public function handle(Application $application)
    {
        $servers = $application->additional_servers;
        $servers->push($application->destination->server);
        foreach ($servers as $server) {
            $is_main_server = $application->destination->server->id === $server->id;
            if (! $server->isFunctional()) {
                if ($is_main_server) {
                    $application->update(['status' => 'exited:unhealthy']);

                    continue;
                } else {
                    $application->additional_servers()->updateExistingPivot($server->id, ['status' => 'exited:unhealthy']);

                    continue;
                }
            }
            $container = instant_remote_process(["docker container inspect $(docker container ls -q --filter 'label=coolify.applicationId={$application->id}' --filter 'label=coolify.pullRequestId=0') --format '{{json .}}'"], $server, false);
            $container = format_docker_command_output_to_json($container);
            if ($container->count() === 1) {
                $container = $container->first();
                $containerStatus = data_get($container, 'State.Status');
                $containerHealth = data_get($container, 'State.Health.Status', 'unhealthy');
                if ($is_main_server) {
                    $statusFromDb = $application->status;
                    if ($statusFromDb !== $containerStatus) {
                        $application->update(['status' => "$containerStatus:$containerHealth"]);
                    }
                } else {
                    $additional_server = $application->additional_servers()->wherePivot('server_id', $server->id);
                    $statusFromDb = $additional_server->first()->pivot->status;
                    if ($statusFromDb !== $containerStatus) {
                        $additional_server->updateExistingPivot($server->id, ['status' => "$containerStatus:$containerHealth"]);
                    }
                }
            } else {
                if ($is_main_server) {
                    $application->update(['status' => 'exited:unhealthy']);

                    continue;
                } else {
                    $application->additional_servers()->updateExistingPivot($server->id, ['status' => 'exited:unhealthy']);

                    continue;
                }
            }
        }
    }
}
