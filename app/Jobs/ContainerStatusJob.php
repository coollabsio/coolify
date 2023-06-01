<?php

namespace App\Jobs;

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ContainerStatusJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $container_name;
    public string|null $pull_request_id;
    public Application $application;

    public function __construct($application, string $container_name, string|null $pull_request_id = null)
    {
        $this->application = $application;
        $this->container_name = $container_name;
        $this->pull_request_id = $pull_request_id;
    }
    public function uniqueId(): string
    {
        return $this->container_name;
    }
    public function handle(): void
    {
        try {
            $status = get_container_status(server: $this->application->destination->server, container_id: $this->container_name, throwError: false);
            ray('Container ' . $this->container_name . ' statuus is ' . $status);
            if ($this->pull_request_id) {
                $preview = ApplicationPreview::findPreviewByApplicationAndPullId($this->application->id, $this->pull_request_id);
                $preview->status = $status;
                $preview->save();
            } else {
                $this->application->status = $status;
                $this->application->save();
            }
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }
    }
    protected function check_container_status()
    {
        if ($this->application->destination->server) {
            $this->application->status = get_container_status(server: $this->application->destination->server, container_id: $this->application->uuid);
            $this->application->save();
        }
    }
    // protected function check_all_servers()
    // {
    //     $servers = Server::all()->reject(fn (Server $server) => $server->settings->is_build_server);
    //     $applications = Application::all();
    //     $not_found_applications = $applications;
    //     $containers = collect();
    //     foreach ($servers as $server) {
    //         $output = instant_remote_process(['docker ps -a -q --format \'{{json .}}\''], $server);
    //         $containers = $containers->concat(format_docker_command_output_to_json($output));
    //     }
    //     foreach ($containers as $container) {
    //         $found_application = $applications->filter(function ($value, $key) use ($container) {
    //             return $value->uuid == $container['Names'];
    //         })->first();
    //         if ($found_application) {
    //             $not_found_applications = $not_found_applications->filter(function ($value, $key) use ($found_application) {
    //                 return $value->uuid != $found_application->uuid;
    //             });
    //             $found_application->status = $container['State'];
    //             $found_application->save();
    //             Log::info('Found application: ' . $found_application->uuid . '. Set status to: ' . $found_application->status);
    //         }
    //     }
    //     foreach ($not_found_applications as $not_found_application) {
    //         $not_found_application->status = 'exited';
    //         $not_found_application->save();
    //         Log::info('Not found application: ' . $not_found_application->uuid . '. Set status to: ' . $not_found_application->status);
    //     }
    // }

}
