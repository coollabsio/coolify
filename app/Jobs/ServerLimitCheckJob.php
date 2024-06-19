<?php

namespace App\Jobs;

use App\Models\Team;
use App\Notifications\Server\ForceDisabled;
use App\Notifications\Server\ForceEnabled;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ServerLimitCheckJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 4;

    public function backoff(): int
    {
        return isDev() ? 1 : 3;
    }

    public function __construct(public Team $team) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->team->uuid))];
    }

    public function uniqueId(): int
    {
        return $this->team->uuid;
    }

    public function handle()
    {
        try {
            $servers = $this->team->servers;
            $servers_count = $servers->count();
            $limit = data_get($this->team->limits, 'serverLimit', 2);
            $number_of_servers_to_disable = $servers_count - $limit;
            ray('ServerLimitCheckJob', $this->team->uuid, $servers_count, $limit, $number_of_servers_to_disable);
            if ($number_of_servers_to_disable > 0) {
                ray('Disabling servers');
                $servers = $servers->sortbyDesc('created_at');
                $servers_to_disable = $servers->take($number_of_servers_to_disable);
                $servers_to_disable->each(function ($server) {
                    $server->forceDisableServer();
                    $this->team->notify(new ForceDisabled($server));
                });
            } elseif ($number_of_servers_to_disable === 0) {
                $servers->each(function ($server) {
                    if ($server->isForceDisabled()) {
                        $server->forceEnableServer();
                        $this->team->notify(new ForceEnabled($server));
                    }
                });
            }
        } catch (\Throwable $e) {
            send_internal_notification('ServerLimitCheckJob failed with: '.$e->getMessage());
            ray($e->getMessage());

            return handleError($e);
        }
    }
}
