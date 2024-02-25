<?php

namespace App\Jobs;

use App\Models\Team;
use App\Notifications\Server\DisabledDueToOverflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ServerOverflowJob implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 4;
    public function backoff(): int
    {
        return isDev() ? 1 : 3;
    }
    public function __construct(public Team $team)
    {
    }
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
            ray('ServerOverflowJob');
            $servers = $this->team->servers;
            $servers_count = $servers->count();
            $limit = $this->team->limits['serverLimit'];
            $number_of_servers_to_disable = $servers_count - $limit;
            ray($number_of_servers_to_disable, $servers_count, $limit);
            if ($number_of_servers_to_disable > 0) {
                ray('Disabling servers');
                $servers = $servers->sortBy('created_at');
                $servers_to_disable = $servers->take($number_of_servers_to_disable);
                $servers_to_disable->each(function ($server) {
                    $server->disableServerDueToOverflow();
                    $this->team->notify(new DisabledDueToOverflow($server));
                });
            }
        } catch (\Throwable $e) {
            send_internal_notification('ServerOverflowJob failed with: ' . $e->getMessage());
            ray($e->getMessage());
            return handleError($e);
        }
    }

}
