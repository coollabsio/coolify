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

class ContainerStopJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $application_id,
        public Server $server,
    ) {
    }
    public function uniqueId(): int
    {
        return $this->application_id;
    }
    public function handle(): void
    {
        try {
            $application = Application::find($this->application_id)->first();
            instant_remote_process(["docker rm -f {$application->uuid}"], $this->server);
            $application->status = get_container_status(server: $application->destination->server, container_id: $application->uuid);
            $application->save();
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }
    }
}
