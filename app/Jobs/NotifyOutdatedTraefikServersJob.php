<?php

namespace App\Jobs;

use App\Models\Team;
use App\Notifications\Server\TraefikVersionOutdated;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Fluent;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyOutdatedTraefikServersJob implements ShouldBeEncrypted, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public function __construct(
        public int $teamId,
        public string $scanId,
        public array $servers
    )
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $teamServers = collect($this->servers)
            ->map(fn (array $server) => new Fluent($server))
            ->values();

        if ($teamServers->isEmpty()) {
            return;
        }

        $team = Team::find($this->teamId);
        if (! $team) {
            return;
        }

        $team->notify(new TraefikVersionOutdated($teamServers));
    }
}
