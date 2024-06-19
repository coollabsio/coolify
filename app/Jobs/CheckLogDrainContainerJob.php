<?php

namespace App\Jobs;

use App\Actions\Server\InstallLogDrain;
use App\Models\Server;
use App\Notifications\Container\ContainerRestarted;
use App\Notifications\Container\ContainerStopped;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Sleep;

class CheckLogDrainContainerJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Server $server) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->server->id))->dontRelease()];
    }

    public function uniqueId(): int
    {
        return $this->server->id;
    }

    public function healthcheck()
    {
        $status = instant_remote_process(["docker inspect --format='{{json .State.Status}}' coolify-log-drain"], $this->server, false);
        if (str($status)->contains('running')) {
            return true;
        } else {
            return false;
        }
    }

    public function handle()
    {
        // ray("checking log drain statuses for {$this->server->id}");
        try {
            if (! $this->server->isFunctional()) {
                return;
            }
            $containers = instant_remote_process(['docker container ls -q'], $this->server, false);
            if (! $containers) {
                return;
            }
            $containers = instant_remote_process(["docker container inspect $(docker container ls -q) --format '{{json .}}'"], $this->server);
            $containers = format_docker_command_output_to_json($containers);

            $foundLogDrainContainer = $containers->filter(function ($value, $key) {
                return data_get($value, 'Name') === '/coolify-log-drain';
            })->first();
            if (! $foundLogDrainContainer || ! $this->healthcheck()) {
                ray('Log drain container not found or unhealthy. Restarting...');
                InstallLogDrain::run($this->server);
                Sleep::for(10)->seconds();
                if ($this->healthcheck()) {
                    if ($this->server->log_drain_notification_sent) {
                        $this->server->team?->notify(new ContainerRestarted('Coolify Log Drainer', $this->server));
                        $this->server->update(['log_drain_notification_sent' => false]);
                    }

                    return;
                }
                if (! $this->server->log_drain_notification_sent) {
                    ray('Log drain container still unhealthy. Sending notification...');
                    // $this->server->team?->notify(new ContainerStopped('Coolify Log Drainer', $this->server, null));
                    $this->server->update(['log_drain_notification_sent' => true]);
                }
            } else {
                if ($this->server->log_drain_notification_sent) {
                    $this->server->team?->notify(new ContainerRestarted('Coolify Log Drainer', $this->server));
                    $this->server->update(['log_drain_notification_sent' => false]);
                }
            }
        } catch (\Throwable $e) {
            if (! isCloud()) {
                send_internal_notification("CheckLogDrainContainerJob failed on ({$this->server->id}) with: ".$e->getMessage());
            }
            ray($e->getMessage());

            return handleError($e);
        }
    }
}
