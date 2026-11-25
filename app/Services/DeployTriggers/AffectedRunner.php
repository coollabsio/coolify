<?php

namespace App\Services\DeployTriggers;

use App\Models\Server;
use App\Services\DeployTriggers\Contract\AffectedContractException;
use App\Services\DeployTriggers\Contract\EvaluateRequest;
use App\Services\DeployTriggers\Contract\EvaluateResponse;

interface AffectedRunner
{
    public function supports(Server $server): bool;

    /**
     * @throws AffectedRunnerException When the remote run fails or times out.
     * @throws AffectedContractException When the output breaks the contract.
     */
    public function evaluate(Server $server, EvaluateRequest $request, GitAccess $access): EvaluateResponse;
}
