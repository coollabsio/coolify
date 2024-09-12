<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Process;

class CleanupStaleMultiplexedConnections implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        Server::chunk(100, function ($servers) {
            foreach ($servers as $server) {
                $this->cleanupStaleConnection($server);
            }
        });
    }

    private function cleanupStaleConnection(Server $server)
    {
        $muxSocket = "/tmp/mux_{$server->id}";
        $checkCommand = "ssh -O check -o ControlPath=$muxSocket {$server->user}@{$server->ip} 2>/dev/null";
        $checkProcess = Process::run($checkCommand);

        if ($checkProcess->exitCode() !== 0) {
            $closeCommand = "ssh -O exit -o ControlPath=$muxSocket {$server->user}@{$server->ip} 2>/dev/null";
            Process::run($closeCommand);
        }
    }
}
