<?php

namespace App\Actions\Service;

use App\Actions\Server\CleanupDocker;
use App\Models\Service;
use App\Services\EdgeProxyRemotePortForwardService;
use App\Services\EdgeProxyRemoteRouteService;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteService
{
    use AsAction;

    public function handle(Service $service, bool $deleteVolumes, bool $deleteConnectedNetworks, bool $deleteConfigurations, bool $dockerCleanup)
    {
        $server = data_get($service, 'server');

        try {
            if ($deleteVolumes && $server->isFunctional()) {
                $storagesToDelete = collect([]);

                $service->environment_variables()->delete();
                $commands = [];
                foreach ($service->applications()->get() as $application) {
                    $storages = $application->persistentStorages()->get();
                    foreach ($storages as $storage) {
                        $storagesToDelete->push($storage);
                    }
                }
                foreach ($service->databases()->get() as $database) {
                    $storages = $database->persistentStorages()->get();
                    foreach ($storages as $storage) {
                        $storagesToDelete->push($storage);
                    }
                }
                foreach ($storagesToDelete as $storage) {
                    $commands[] = 'docker volume rm -f '.escapeshellarg($storage->name);
                }

                // Execute volume deletion first, this must be done first otherwise volumes will not be deleted.
                if (! empty($commands)) {
                    foreach ($commands as $command) {
                        $result = $this->runRemoteCommands([$command], $server, false);
                        if ($result !== null && $result !== 0) {
                            Log::error('Error deleting volumes: '.$result);
                        }
                    }
                }
            }

            if ($deleteConnectedNetworks) {
                $service->deleteConnectedNetworks();
            }

            $this->runRemoteCommands(["docker rm -f $service->uuid"], $server, throwError: false);
        } catch (\Throwable $exception) {
            throw new \RuntimeException($exception->getMessage(), previous: $exception);
        }

        $this->cleanupEdgeProxyState($service);

        if ($deleteConfigurations) {
            $service->deleteConfigurations();
        }
        foreach ($service->applications()->get() as $application) {
            $application->forceDelete();
        }
        foreach ($service->databases()->get() as $database) {
            $database->forceDelete();
        }
        foreach ($service->scheduled_tasks as $task) {
            $task->delete();
        }
        $service->tags()->detach();
        $service->forceDelete();

        if ($dockerCleanup) {
            CleanupDocker::dispatch($server, false, false);
        }
    }

    protected function runRemoteCommands(array $commands, $server, bool $throwError = true): ?string
    {
        return instant_remote_process($commands, $server, $throwError);
    }

    protected function cleanupEdgeProxyState(Service $service): void
    {
        try {
            app(EdgeProxyRemoteRouteService::class)->deleteService($service);
        } catch (\Throwable $exception) {
            Log::warning('Failed to delete edge proxy route file for service.', [
                'service_uuid' => $service->uuid,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        try {
            app(EdgeProxyRemotePortForwardService::class)->deleteService($service);
        } catch (\Throwable $exception) {
            Log::warning('Failed to delete edge port proxy for service.', [
                'service_uuid' => $service->uuid,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
