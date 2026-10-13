<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Notifications\Server\Unreachable;
use Illuminate\Console\Command;

class CleanupUnreachableServers extends Command
{
    protected $signature = 'cleanup:unreachable-servers';

    protected $description = 'Cleanup Unreachable Servers (7 days)';

    public function handle()
    {
        echo "Running unreachable server cleanup...\n";
        $servers = Server::where('unreachable_count', '>=', 3)->whereHas('notificationThrottles', fn ($query) => $query->where('notification', Unreachable::class))->where('updated_at', '<', now()->subDays(7))->get();
        if ($servers->count() > 0) {
            foreach ($servers as $server) {
                echo "Cleanup unreachable server ($server->id) with name $server->name";
                if (isCloud()) {
                    $server->update([
                        'ip' => '1.2.3.4',
                    ]);
                } else {
                    $server->forceDisableServer();
                }
            }
        }
    }
}
