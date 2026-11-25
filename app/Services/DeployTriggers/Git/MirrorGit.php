<?php

namespace App\Services\DeployTriggers\Git;

use App\Services\DeployTriggers\AffectedRunnerException;

/**
 * Runs a mirror script where the repository mirrors live: in a helper container on a server, or locally in tests.
 */
interface MirrorGit
{
    /**
     * @throws AffectedRunnerException When the script cannot run or exits with an error.
     */
    public function run(string $script, int $timeoutSeconds): string;

    public function mirrorsPath(): string;

    public function runPath(): string;

    /**
     * True only for local tests, which use file:// repositories.
     */
    public function allowsFileProtocol(): bool;
}
