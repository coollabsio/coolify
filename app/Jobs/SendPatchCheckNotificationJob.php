<?php

namespace App\Jobs;

use App\Models\Server;
use App\Notifications\Server\ServerPatchCheck;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPatchCheckNotificationJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    /**
     * @param  array<int>  $serverIds  Server IDs from the batch that triggered this job
     */
    public function __construct(public array $serverIds = [])
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $query = Server::whereNotNull('patch_check_data')
            ->whereRelation('settings', 'is_reachable', true)
            ->with('team');

        // Scope to specific servers from the batch to avoid race conditions
        if (! empty($this->serverIds)) {
            $query->whereIn('id', $this->serverIds);
        }

        $servers = $query->get();

        if ($servers->isEmpty()) {
            return;
        }

        $servers->groupBy('team_id')->each(function ($teamServers) {
            $team = $teamServers->first()->team;
            if (! $team) {
                return;
            }

            $team->notify(new ServerPatchCheck($teamServers, bundledOnly: true));
        });

        // Clear patch data only for the servers in this batch
        Server::whereIn('id', $servers->pluck('id'))->update(['patch_check_data' => null]);
    }
}
