<?php

namespace Tests\Support;

use App\Services\DeployTriggers\AffectedRunnerException;
use App\Services\DeployTriggers\Git\MirrorGit;
use Illuminate\Support\Facades\Process;

/**
 * Runs mirror scripts on this machine with bash and the local git, for tests against file:// repositories.
 */
final class LocalMirrorGit implements MirrorGit
{
    /** @var list<string> */
    public array $scripts = [];

    public function __construct(private readonly string $root) {}

    public function run(string $script, int $timeoutSeconds): string
    {
        $this->scripts[] = $script;
        $process = Process::timeout($timeoutSeconds)->input($script)->run(['bash', '-s']);
        if ($process->exitCode() !== 0 && ! str_contains($process->output(), '@@ERROR ')) {
            throw new AffectedRunnerException(trim($process->errorOutput()) ?: "exit {$process->exitCode()}");
        }

        return $process->output();
    }

    public function mirrorsPath(): string
    {
        return $this->root.'/mirrors';
    }

    public function runPath(): string
    {
        return $this->root.'/run';
    }

    public function allowsFileProtocol(): bool
    {
        return true;
    }
}
