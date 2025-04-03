<?php

namespace App\Actions\Proxy;

use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Lorisleiva\Actions\Concerns\AsAction;

class StopProxy
{
    use AsAction;

    public function handle(Server $server, bool $forceStop = true)
    {
        try {
            $containerName = $server->isSwarm() ? 'coolify-proxy_traefik' : 'coolify-proxy';
            $timeout = 30;

            $process = $this->stopContainer($containerName, $timeout);

            $startTime = Carbon::now()->getTimestamp();
            while ($process->running()) {
                if (Carbon::now()->getTimestamp() - $startTime >= $timeout) {
                    $this->forceStopContainer($containerName, $server);
                    break;
                }
                usleep(100000);
            }

            $this->removeContainer($containerName, $server);
        } catch (\Throwable $e) {
            return handleError($e);
        } finally {
            $server->proxy->force_stop = $forceStop;
            $server->proxy->status = 'exited';
            $server->save();
        }
    }

    private function stopContainer(string $containerName, int $timeout): InvokedProcess
    {
        return Process::timeout($timeout)->start("docker stop --time=$timeout $containerName");
    }

    private function forceStopContainer(string $containerName, Server $server)
    {
        instant_remote_process(["docker kill $containerName"], $server, throwError: false);
    }

    private function removeContainer(string $containerName, Server $server)
    {
        instant_remote_process(["docker rm -f $containerName"], $server, throwError: false);
    }
}
