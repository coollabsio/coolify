<?php

use App\Actions\RemoteProcess\DispatchRemoteProcess;
use App\Data\RemoteProcessArgs;
use App\Models\Server;
use Spatie\Activitylog\Contracts\Activity;

if (!function_exists('remoteProcess')) {
    /**
     * Run a Coolify Process, which SSH's asynchronously into a machine to run the command(s).
     * @TODO Change 'root' to 'coolify' when it's able to run Docker commands without sudo
     *
     */
    function remoteProcess(
        string    $command,
        string    $destination
    ): Activity {
        $found_server = Server::where('name', $destination)->first();
        if (!$found_server) {
            throw new \RuntimeException('Server not found.');
        }
        $found_team  = auth()->user()->teams->pluck('id')->contains($found_server->team_id);
        if (!$found_team) {
            throw new \RuntimeException('You do not have access to this server.');
        }
        return resolve(DispatchRemoteProcess::class, [
            'remoteProcessArgs' => new RemoteProcessArgs(
                destination: $found_server->ip,
                command: $command,
                port: $found_server->port,
                user: $found_server->user,
            ),
        ])();
    }
}
