<?php

use App\Actions\RemoteProcess\DispatchRemoteProcess;
use Spatie\Activitylog\Contracts\Activity;

if (! function_exists('remoteProcess')) {

    /**
     * Run a Coolify Process, which SSH's into a machine to run the command(s).
     *
     */
    function remoteProcess($command, $destination): Activity
    {
        return resolve(DispatchRemoteProcess::class, [
            'destination' => $destination,
            'command' => $command,
        ])();
    }
}
