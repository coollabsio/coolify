<?php

namespace App\Services\Remote;

class RemoteProcessExecutionerManager
{
    public function __construct(private RemoteProcessExecutionerService $executionerService) {}

    public function execute(string $command, bool $throwOnError = true): string | null
    {
        $result = $this->executionerService->execute($command);

        $exitCode = $result->getExitCode();
        $output = $result->getOutput();

        if($exitCode === 0) {
            if($output === 'null') {
                return null;
            }

            return $output;
        }

        if(!$throwOnError) {
            return null;
        }

        // TODO: Refactor to Error Exclude Service.
        return excludeCertainErrors($output, $exitCode);
    }
}
