<?php

namespace App\Jobs;

use App\Actions\Proxy\StartProxy;
use App\Actions\Proxy\StopProxy;
use App\Enums\ProxyTypes;
use App\Events\ProxyStatusChangedUI;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
            $teamId = $this->server->team_id;

            // Stop proxy
            StopProxy::run($this->server, restarting: true);

            // Clear force_stop flag
            $this->server->proxy->force_stop = false;
            $this->server->save();

            // Start proxy asynchronously to get activity
            $activity = StartProxy::run($this->server, force: true, restarting: true);

            // Store activity ID and dispatch event with it
            if ($activity && is_object($activity)) {
                $this->activity_id = $activity->id;
                ProxyStatusChangedUI::dispatch($teamId, $this->activity_id);
            }

            // Check Traefik version after restart (same as original behavior)
            if ($this->server->proxyType() === ProxyTypes::TRAEFIK->value) {
                $traefikVersions = get_traefik_versions();
                if ($traefikVersions !== null) {
                    CheckTraefikVersionForServerJob::dispatch($this->server, $traefikVersions);
                } else {
                    Log::warning('Traefik version check skipped: versions.json data unavailable', [
                        'server_id' => $this->server->id,
                        'server_name' => $this->server->name,
                    ]);
                }
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
