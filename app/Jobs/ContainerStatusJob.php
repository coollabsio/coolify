<?php

namespace App\Jobs;

use App\Actions\RemoteProcess\RunRemoteProcess;
use App\Models\Application;
use App\Models\Server;
use App\Traits\Docker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Tests\Support\Output;

class ContainerStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        //
    }
    public function handle(): void
    {
        try {
            $servers = Server::all()->reject(fn (Server $server) => $server->settings->is_build_server);
            $applications = Application::all();
            $not_found_applications = $applications;
            $containers = collect();
            foreach ($servers as $server) {
                $private_key_location = savePrivateKey($server);
                $ssh_command = generateSshCommand($private_key_location, $server->ip, $server->user, $server->port, 'docker ps -a -q --format \'{{json .}}\'');
                $process = Process::run($ssh_command);
                $output = trim($process->output());
                $containers = $containers->concat(formatDockerCmdOutputToJson($output));
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
                    Log::info('Found application: ' . $found_application->uuid . '.Set status to: ' . $found_application->status);
                }
            }
            foreach ($not_found_applications as $not_found_application) {
                $not_found_application->status = 'exited';
                $not_found_application->save();
                Log::info('Not found application: ' . $not_found_application->uuid . '.Set status to: ' . $not_found_application->status);
            }
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }
    }
}
