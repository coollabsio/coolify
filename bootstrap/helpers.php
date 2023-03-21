<?php

use App\Services\CoolifyProcess;
use Spatie\Activitylog\Contracts\Activity;

if (! function_exists('remoteProcess')) {

    /**
     * Run a Coolify Process, which SSH's into a machine to run the command(s).
     *
     */
    function remoteProcess($command, $destination): Activity
    {
        return resolve(CoolifyProcess::class, [
            'destination' => $destination,
            'command' => $command,
        ])();
    }
}
