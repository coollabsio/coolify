<?php

namespace App\Console\Commands;

use App\Models\ApplicationDeploymentQueue;
use Illuminate\Console\Command;

class CleanupApplicationDeploymentQueue extends Command
{
    protected $signature = 'cleanup:application-deployment-queue {--team-id=}';

    protected $description = 'CleanupApplicationDeploymentQueue';

    public function handle()
    {
        $team_id = $this->option('team-id');
        $servers = \App\Models\Server::where('team_id', $team_id)->get();
        foreach ($servers as $server) {
            $deployments = ApplicationDeploymentQueue::whereIn('status', ['in_progress', 'queued'])->where('server_id', $server->id)->get();
            foreach ($deployments as $deployment) {
                $deployment->update(['status' => 'failed']);
                instant_remote_process(['docker rm -f '.$deployment->deployment_uuid], $server, false);
            }
        }
    }
}
