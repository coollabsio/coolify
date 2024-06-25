<?php

namespace App\Services\Remote;

use Illuminate\Process\InvokedProcess;

class RemoteProcessExecutionerManager
{
    public function __construct(private RemoteProcessExecutionerService $executionerService) {}

    public function execute(string $command, bool $throwOnError = true): ?string
    {
        $result = $this->executionerService->execute($command);

        $exitCode = $result->getExitCode();
        $output = $result->getOutput();

        if ($exitCode === 0) {
            if ($output === 'null') {
                return null;
            }

            return $output;
        }

        if (! $throwOnError) {
            return null;
        }

        // TODO: Refactor to Error Exclude Service.
        return excludeCertainErrors($output, $exitCode);
    }

    public function createAwaitingProcess(string $command, int $timeout = 3600, int $idleTimeout = 3600, ?callable $output = null): InvokedProcess
    {
        return $this->executionerService->createAwaitingProcess($command, $timeout, $idleTimeout, $output);
    }
}
