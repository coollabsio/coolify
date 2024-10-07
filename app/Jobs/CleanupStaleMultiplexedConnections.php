<?php

namespace App\Jobs;

use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class CleanupStaleMultiplexedConnections implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        $this->cleanupStaleConnections();
        $this->cleanupNonExistentServerConnections();
    }

    private function cleanupStaleConnections()
    {
        $muxFiles = Storage::disk('ssh-mux')->files();

        foreach ($muxFiles as $muxFile) {
            $serverUuid = $this->extractServerUuidFromMuxFile($muxFile);
            $server = Server::where('uuid', $serverUuid)->first();

            if (! $server) {
                $this->removeMultiplexFile($muxFile);

                continue;
            }

            $muxSocket = "/var/www/html/storage/app/ssh/mux/{$muxFile}";
            $checkCommand = "ssh -O check -o ControlPath={$muxSocket} {$server->user}@{$server->ip} 2>/dev/null";
            $checkProcess = Process::run($checkCommand);

            if ($checkProcess->exitCode() !== 0) {
                $this->removeMultiplexFile($muxFile);
            } else {
                $muxContent = Storage::disk('ssh-mux')->get($muxFile);
                $establishedAt = Carbon::parse(substr($muxContent, 37));
                $expirationTime = $establishedAt->addSeconds(config('constants.ssh.mux_persist_time'));

                if (Carbon::now()->isAfter($expirationTime)) {
                    $this->removeMultiplexFile($muxFile);
                }
            }
        }
    }

    private function cleanupNonExistentServerConnections()
    {
        $muxFiles = Storage::disk('ssh-mux')->files();
        $existingServerUuids = Server::pluck('uuid')->toArray();

        foreach ($muxFiles as $muxFile) {
            $serverUuid = $this->extractServerUuidFromMuxFile($muxFile);
            if (! in_array($serverUuid, $existingServerUuids)) {
                $this->removeMultiplexFile($muxFile);
            }
        }
    }

    private function extractServerUuidFromMuxFile($muxFile)
    {
        return substr($muxFile, 4);
    }

    private function removeMultiplexFile($muxFile)
    {
        $muxSocket = "/var/www/html/storage/app/ssh/mux/{$muxFile}";
        $closeCommand = "ssh -O exit -o ControlPath={$muxSocket} localhost 2>/dev/null";
        Process::run($closeCommand);
        Storage::disk('ssh-mux')->delete($muxFile);
    }
}
