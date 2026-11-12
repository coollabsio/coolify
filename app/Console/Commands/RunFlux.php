<?php

namespace App\Console\Commands;

use App\Actions\Sentinel\FluxRuntime;
use Illuminate\Console\Command;

/**
 * The s6 service `flux` runs this command. It replaces itself with Flux, so s6 supervises Flux directly and
 * the secrets reach Flux without a shell or a file.
 */
class RunFlux extends Command
{
    /** The finish script of the service keeps Flux down after this exit code, until RestartFlux starts it. */
    public const EXIT_NOT_NEEDED = 125;

    protected $signature = 'flux:run';

    protected $description = 'Start Flux in this container when Nodes are in use (s6 service)';

    public function handle(): int
    {
        if (! FluxRuntime::shouldRun()) {
            $this->line('   INFO  Flux stays down: Nodes are off and no Node exists, or Flux runs outside this container.');

            return self::EXIT_NOT_NEEDED;
        }

        $environment = FluxRuntime::run();
        $binary = (string) config('constants.flux.binary');
        $this->line('   INFO  Flux is starting...');
        pcntl_exec($binary, [], $environment);

        $this->error("Cannot start {$binary}: ".pcntl_strerror(pcntl_get_last_error()));

        return self::FAILURE;
    }
}
