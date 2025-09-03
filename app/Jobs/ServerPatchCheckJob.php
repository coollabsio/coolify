<?php

namespace App\Jobs;

use App\Actions\Server\CheckUpdates;
use App\Models\Server;
use App\Notifications\Server\ServerPatchCheck;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ServerPatchCheckJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 600;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('server-patch-check-'.$this->server->uuid))->expireAfter(600)->dontRelease()];
    }

    public function __construct(public Server $server) {}

    public function handle(): void
    {
        try {
            if ($this->server->serverStatus() === false) {
                return;
            }

            $team = data_get($this->server, 'team');
            if (! $team) {
                return;
            }

            // Check for updates
            $patchData = CheckUpdates::run($this->server);

            if (isset($patchData['error'])) {
                $team->notify(new ServerPatchCheck($this->server, $patchData));

                return; // Skip if there's an error checking for updates
            }

            $totalUpdates = $patchData['total_updates'] ?? 0;

            // Only send notification if there are updates available
            if ($totalUpdates > 0) {
                $team->notify(new ServerPatchCheck($this->server, $patchData));
            }
        } catch (\Throwable $e) {
            // Log error but don't fail the job
            \Illuminate\Support\Facades\Log::error('ServerPatchCheckJob failed: '.$e->getMessage(), [
                'server_id' => $this->server->id,
                'server_name' => $this->server->name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
