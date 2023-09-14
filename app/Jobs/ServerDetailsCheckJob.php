<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Str;

class ServerDetailsCheckJob implements ShouldQueue, ShouldBeUnique, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 120;

    public function __construct(public Server $server)
    {
    }

    public function middleware(): array
    {
        return [new WithoutOverlapping($this->server->uuid)];
    }

    public function uniqueId(): string
    {
        return $this->server->uuid;
    }

    public function handle(): void
    {
        try {
            ray()->clearAll();
            $containers = instant_remote_process(["docker container inspect $(docker container ls -q) --format '{{json .}}'"], $this->server);
            $containers = format_docker_command_output_to_json($containers);
            $applications = $this->server->applications();
            // ray($applications);
            // ray(format_docker_command_output_to_json($containers));
            foreach ($applications as $application) {
                $uuid = data_get($application, 'uuid');
                $foundContainer = $containers->filter(function ($value, $key) use ($uuid) {
                    $image = data_get($value, 'Config.Image');
                    return Str::startsWith($image, $uuid);
                })->first();

                if ($foundContainer) {
                    $containerStatus = data_get($foundContainer, 'State.Status');
                    $databaseStatus = data_get($application, 'status');
                    ray($containerStatus, $databaseStatus);
                    if ($containerStatus !== $databaseStatus) {
                        // $application->update(['status' => $containerStatus]);
                    }
                }
            }
            // foreach ($containers as $container) {
            //     $labels = format_docker_labels_to_json(data_get($container,'Config.Labels'));
            //     $foundLabel = $labels->filter(fn ($value, $key) => Str::startsWith($key, 'coolify.applicationId'));
            //     if ($foundLabel->count() > 0) {
            //         $appFound = $applications->where('id', $foundLabel['coolify.applicationId'])->first();
            //         if ($appFound) {
            //             $containerStatus = data_get($container, 'State.Status');
            //             $databaseStatus = data_get($appFound, 'status');
            //             ray($containerStatus, $databaseStatus);
            //         }
            //     }
            // }
        } catch (\Throwable $e) {
            // send_internal_notification('ServerDetailsCheckJob failed with: ' . $e->getMessage());
            ray($e->getMessage());
            throw $e;
        }
    }
}
