<?php

namespace App\Jobs;

use App\Actions\Server\CheckUpdates;
use App\Models\Server;
use App\Notifications\Server\ServerPatchCheck;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ServerPatchCheckJob implements ShouldBeEncrypted, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 600;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('server-patch-check-'.$this->server->uuid))->expireAfter(600)->releaseAfter(60)];
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

            $totalUpdates = $patchData['total_updates'] ?? 0;

            if (isset($patchData['error']) || $totalUpdates > 0) {
                $this->server->update(['patch_check_data' => $patchData]);

                // Send immediate notification to channels that have bundling disabled
                $unbundledChannels = $team->getEnabledChannels('server_patch', unbundledOnly: true);
                if (! empty($unbundledChannels)) {
                    $team->notify(new ServerPatchCheck(collect([$this->server]), unbundledOnly: true));
                }
            } else {
                $this->server->update(['patch_check_data' => null]);
            }
        } catch (\Throwable $e) {
            Log::error('ServerPatchCheckJob failed: '.$e->getMessage(), [
                'server_id' => $this->server->id,
                'server_name' => $this->server->name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
