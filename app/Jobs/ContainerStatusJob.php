<?php

namespace App\Jobs;

use App\Models\Application;
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

    private Application $application;
    public function __construct(
        public string|null $application_id = null,
    ) {
        if ($this->application_id) {
            $this->application = Application::find($this->application_id);
        }
    }
    public function uniqueId(): string
    {
        return $this->application_id;
    }
    public function handle(): void
    {
        try {
            if ($this->application->uuid) {
                $this->check_container_status();
            } else {
                $this->check_all_servers();
            }
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }
    }
    protected function check_all_servers()
    {
        $servers = Server::all()->reject(fn (Server $server) => $server->settings->is_build_server);
        $applications = Application::all();
        $not_found_applications = $applications;
        $containers = collect();
        foreach ($servers as $server) {
            $output = instant_remote_process(['docker ps -a -q --format \'{{json .}}\''], $server);
            $containers = $containers->concat(format_docker_command_output_to_json($output));
        }
        foreach ($containers as $container) {
            $found_application = $applications->filter(function ($value, $key) use ($container) {
                return $value->uuid == $container['Names'];
            })->first();
            if ($found_application) {
                $not_found_applications = $not_found_applications->filter(function ($value, $key) use ($found_application) {
                    return $value->uuid != $found_application->uuid;
                });
                $found_application->status = $container['State'];
                $found_application->save();
                Log::info('Found application: ' . $found_application->uuid . '. Set status to: ' . $found_application->status);
            }
        }
        foreach ($not_found_applications as $not_found_application) {
            $not_found_application->status = 'exited';
            $not_found_application->save();
            Log::info('Not found application: ' . $not_found_application->uuid . '. Set status to: ' . $not_found_application->status);
        }
    }
    protected function check_container_status()
    {
        if ($this->application->destination->server) {
            $this->application->status = get_container_status(server: $this->application->destination->server, container_id: $this->application->uuid);
            $this->application->save();
        }
    }
}
