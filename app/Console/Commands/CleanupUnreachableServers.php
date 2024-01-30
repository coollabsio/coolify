<?php

namespace App\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;

class CleanupUnreachableServers extends Command
{
    protected $signature = 'cleanup:unreachable-servers';
    protected $description = 'Cleanup Unreachable Servers (3 days)';

    public function handle()
    {
        echo "Running unreachable server cleanup...\n";
        $servers = Server::where('unreachable_count', 3)->where('unreachable_notification_sent', true)->where('updated_at', '<', now()->subDays(3))->get();
        if ($servers->count() > 0) {
            foreach ($servers as $server) {
                ray('Cleanup unreachable server', $server->id);
                // $server->update([
                //     'ip' => '1.2.3.4'
                // ]);
            }
        }
    }
}
