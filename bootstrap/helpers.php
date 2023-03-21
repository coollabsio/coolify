<?php

use App\Actions\RemoteProcess\DispatchRemoteProcess;
use App\Data\RemoteProcessArgs;
use Spatie\Activitylog\Contracts\Activity;

if (! function_exists('remoteProcess')) {
    /**
     * Run a Coolify Process, which SSH's asynchronously into a machine to run the command(s).
     * @TODO Change 'root' to 'coolify' when it's able to run Docker commands without sudo
     *
     */
    function remoteProcess(
        string    $command,
        string    $destination,
        ?int      $port = 22,
        ?string   $user = 'root',
    ): Activity
    {
        return resolve(DispatchRemoteProcess::class, [
            'remoteProcessArgs' => new RemoteProcessArgs(
                destination: $destination,
                command: $command,
                port: $port,
                user: $user,
            ),
        ])();
    }
}
