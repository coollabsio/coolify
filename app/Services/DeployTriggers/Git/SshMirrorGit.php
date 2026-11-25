<?php

namespace App\Services\DeployTriggers\Git;

use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use App\Services\DeployTriggers\AffectedRunnerException;
use Throwable;

/**
 * Runs mirror scripts with the stock git of the helper image, in a short-lived container on a server of the team.
 * The script, which holds the credentials, travels on the stdin of SSH and lands only on a tmpfs in the container.
 */
final class SshMirrorGit implements MirrorGit
{
    public const VOLUME = 'coolify-git-mirrors';

    private const BOOTSTRAP = 'umask 077; cat > /run/coolify/script && exec sh /run/coolify/script';

    private bool $imageReady = false;

    public function __construct(private readonly Server $server) {}

    public function run(string $script, int $timeoutSeconds): string
    {
        $image = coolifyHelperImage().':'.getHelperVersion();

        try {
            if (! $this->imageReady) {
                instant_remote_process([
                    'docker image inspect '.escapeshellarg($image).' >/dev/null 2>&1 || timeout 600 docker pull '.escapeshellarg($image).' >/dev/null',
                ], $this->server, throwError: true, timeout: 660, maxAttempts: 1);
                $this->imageReady = true;
            }

            $remoteCommand = ($this->server->isNonRoot() ? 'sudo ' : '')
                .'docker run --rm -i --network host --tmpfs /run/coolify:rw,nosuid,nodev,noexec,size=512m'
                .' -v '.self::VOLUME.':/mirrors --entrypoint sh '.escapeshellarg($image).' -c '.escapeshellarg(self::BOOTSTRAP);
            $process = runSshProcess(
                SshMultiplexingHelper::generateSshStdinCommand($this->server, $remoteCommand, commandTimeout: $timeoutSeconds + 30),
                $timeoutSeconds + 30,
                $script,
            );
            SshMultiplexingHelper::noticeHostKeyMismatch($this->server, $process);
        } catch (Throwable $exception) {
            throw new AffectedRunnerException($exception->getMessage());
        }

        if ($process->exitCode() !== 0 && ! str_contains($process->output(), '@@ERROR ')) {
            throw new AffectedRunnerException(trim($process->errorOutput()) ?: "The git mirror exited with code {$process->exitCode()}.");
        }

        return $process->output();
    }

    public function mirrorsPath(): string
    {
        return '/mirrors';
    }

    public function runPath(): string
    {
        return '/run/coolify';
    }

    public function allowsFileProtocol(): bool
    {
        return false;
    }
}
