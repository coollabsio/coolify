<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Writes the current catch-all on a server whose running proxy has an outdated one
 * (see Server::claimOutdatedDefaultRedirectRefresh()).
 */
class RefreshDefaultRedirectJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 120;

    public function __construct(public Server $server) {}

    public function handle(): void
    {
        if ($this->server->isTransferredAway() || ! $this->server->isFunctional()) {
            return;
        }

        $this->server->setupDefaultRedirect();
    }
}
