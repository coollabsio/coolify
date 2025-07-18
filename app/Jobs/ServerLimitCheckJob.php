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

    public function handle()
    {
        try {
            $servers = $this->team->servers;
            $servers_count = $servers->count();
            $number_of_servers_to_disable = $servers_count - $this->team->limits;
            if ($number_of_servers_to_disable > 0) {
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

            return handleError($e);
        }
    }
}
