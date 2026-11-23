<?php

namespace App\Jobs;

use App\Enums\ProxyTypes;
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

    /**
     * @param  string|null  $claimedHash  The hash claimed by Server::claimOutdatedDefaultRedirectRefresh().
     */
    public function __construct(public Server $server, public ?string $claimedHash = null) {}

    public function handle(): void
    {
        if ($this->server->isTransferredAway() || ! $this->server->isFunctional() || ! $this->proxyIsStillRunning()) {
            return;
        }

        $this->server->setupDefaultRedirect();

        // Only after success: a kept claim is the backoff for a failed refresh.
        if ($this->claimedHash !== null) {
            $this->server->releaseDefaultRedirectRefresh($this->claimedHash);
        }
    }

    /**
     * The proxy can be stopped or changed after the dispatch. Then the refresh must not start the page container again.
     */
    private function proxyIsStillRunning(): bool
    {
        return $this->server->proxy->get('status') === 'running'
            && ! $this->server->proxy->get('force_stop')
            && ! $this->server->isProxyTransitionInProgress()
            && in_array($this->server->proxyType(), [ProxyTypes::TRAEFIK->value, ProxyTypes::CADDY->value], true);
    }
}
