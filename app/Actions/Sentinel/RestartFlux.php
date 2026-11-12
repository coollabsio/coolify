<?php

namespace App\Actions\Sentinel;

use Illuminate\Support\Facades\Process;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Flux is the s6 service `flux` of the Coolify container. Its run script lets www-data control it.
 */
class RestartFlux
{
    use AsAction;

    public const SERVICE_DIRECTORY = '/run/service/flux';

    /**
     * @param  bool  $restart  False only starts a stopped Flux and keeps a running one.
     */
    public function handle(bool $restart = true): void
    {
        if (! is_dir(self::SERVICE_DIRECTORY)) {
            throw new RuntimeException('Flux does not run as a service in this container.');
        }

        // -r restarts a running Flux; -u starts it when its run script stopped it because Nodes were off.
        $result = Process::timeout(10)->run(['/command/s6-svc', ...($restart ? ['-r'] : []), '-u', self::SERVICE_DIRECTORY]);
        if ($result->failed()) {
            throw new RuntimeException('Cannot restart Flux: '.trim($result->errorOutput()));
        }
    }
}
