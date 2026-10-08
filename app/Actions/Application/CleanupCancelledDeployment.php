<?php

namespace App\Actions\Application;

use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * `current_process_id` is the PID of the local SSH client, so it is never killed on a remote server.
 */
class CleanupCancelledDeployment
{
    use AsAction;

    public function handle(ApplicationDeploymentQueue $deployment, int $teamId): void
    {
        $deploymentUuid = $deployment->deployment_uuid;
        $containerStopped = false;
        $firstError = null;

        try {
            foreach ($this->helperContainerServers($deployment, $teamId) as $server) {
                try {
                    $containerExists = instant_remote_process(["docker ps -a --filter name={$deploymentUuid} --format '{{.Names}}'"], $server);

                    if (str($containerExists)->trim()->isNotEmpty()) {
                        instant_remote_process(["docker rm -f {$deploymentUuid}"], $server);
                        $containerStopped = true;

                        // A removed helper cannot stop the Railpack builder, which keeps the memory of its builds.
                        if ($deployment->application?->build_pack === 'railpack') {
                            $helperImage = coolifyHelperImage().':'.getHelperVersion();
                            instant_remote_process([railpackBuilderHelperCommand(railpackBuildxMetadataVolume($server), $helperImage, 'true')], $server, false);
                        }
                    }
                } catch (\Throwable $e) {
                    $firstError ??= $e;
                }
            }

            if ($containerStopped) {
                $deployment->addLogEntry('Deployment container stopped.');
            } elseif (! $firstError) {
                $deployment->addLogEntry('Deployment container not yet started. Will be cancelled when job checks status.');
            }
        } finally {
            $deployment->update(['current_process_id' => null]);
        }

        if ($firstError) {
            throw $firstError;
        }
    }

    /**
     * A restart creates the helper on the deployment server even when a build server was selected.
     *
     * @return Collection<int, Server>
     */
    private function helperContainerServers(ApplicationDeploymentQueue $deployment, int $teamId): Collection
    {
        $serverIds = collect([$deployment->build_server_id, $deployment->server_id])
            ->reject(fn ($id) => is_null($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        return Server::whereTeamId($teamId)
            ->whereKey($serverIds)
            ->get()
            ->sortBy(fn (Server $server) => $serverIds->search($server->id))
            ->values();
    }
}
