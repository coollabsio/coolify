<?php

use App\Services\CoolifyProcess;
use Spatie\Activitylog\Contracts\Activity;

if (! function_exists('coolifyProcess')) {

    /**
     * Run a Coolify Process, which SSH's into a machine to run the command(s).
     *
     */
    function coolifyProcess($command, $destination): Activity
    {
        $process = resolve(CoolifyProcess::class, [
            'destination' => $destination,
            'command' => $command,
        ]);

        $activityLog = $process();

        return $activityLog;
    }
}
