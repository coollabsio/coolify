<?php

namespace App\Jobs;

use App\Actions\Proxy\StartProxy;
use App\Actions\Proxy\StopProxy;
use App\Events\ProxyStatusChangedUI;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class RestartProxyJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 60;

    public ?int $activity_id = null;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('restart-proxy-'.$this->server->uuid))->expireAfter(60)->dontRelease()];
    }

    public function __construct(public Server $server) {}

    public function handle()
    {
        try {
            // Stop proxy
            StopProxy::run($this->server, restarting: true);

            // Clear force_stop flag
            $this->server->proxy->force_stop = false;
            $this->server->save();

            // Start proxy asynchronously - the ProxyStatusChanged event will be dispatched
            // when the remote process completes, which triggers ProxyStatusChangedNotification
            // listener that handles UI updates and Traefik version checks
            $activity = StartProxy::run($this->server, force: true, restarting: true);

            // Store activity ID and dispatch event with it so UI can open activity monitor
            if ($activity && is_object($activity)) {
                $this->activity_id = $activity->id;
                // Dispatch event with activity ID so the UI can show logs
                ProxyStatusChangedUI::dispatch($this->server->team_id, $this->activity_id);
            }

        } catch (\Throwable $e) {
            // Set error status
            $this->server->proxy->status = 'error';
            $this->server->save();

            // Notify UI of error
            ProxyStatusChangedUI::dispatch($this->server->team_id);

            return handleError($e);
        }
    }
}
