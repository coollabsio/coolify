<?php

namespace Tests\Support;

use App\Services\DeployTriggers\AffectedRunnerException;
use App\Services\DeployTriggers\Git\MirrorGit;
use Illuminate\Support\Facades\Process;

/**
 * Runs mirror scripts in a local helper container the way SshMirrorGit does on a server. Opt in with
 * DEPLOY_TRIGGERS_HELPER_IMAGE=coollabsio/coolify-helper:<tag>.
 */
final class DockerMirrorGit implements MirrorGit
{
    /** @var list<string> */
    public array $scripts = [];

    public function __construct(private readonly string $root, private readonly string $image) {}

    public function run(string $script, int $timeoutSeconds): string
    {
        $this->scripts[] = $script;
        $process = Process::timeout($timeoutSeconds)->input($script)->run([
            'docker', 'run', '--rm', '-i', '--user', posix_getuid().':'.posix_getgid(),
            '--tmpfs', '/run/coolify:rw,nosuid,nodev,noexec,size=512m,mode=1777', '-v', "{$this->root}:{$this->root}",
            '--entrypoint', 'sh', $this->image, '-c', 'umask 077; cat > /run/coolify/script && exec sh /run/coolify/script',
        ]);
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
        return '/run/coolify';
    }

    public function allowsFileProtocol(): bool
    {
        return true;
    }
}
