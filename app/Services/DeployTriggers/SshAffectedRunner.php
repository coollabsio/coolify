<?php

namespace App\Services\DeployTriggers;

use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use App\Services\DeployTriggers\Contract\EvaluateRequest;
use App\Services\DeployTriggers\Contract\EvaluateResponse;
use Throwable;

/**
 * Runs `coolify-affected evaluate` in a short-lived helper container on a server of the team.
 */
class SshAffectedRunner implements AffectedRunner
{
    public const SSH_TIMEOUT_SECONDS = 180;

    /**
     * Runs in the helper container: writes the credential files from stdin to /run/coolify, then evaluates the rest of stdin.
     */
    private const CONTAINER_SCRIPT = 'umask 077; mkdir -p /run/coolify; while IFS= read -r line && [ "$line" != "--" ]; do name="${line%%:*}"; printf %s "${line#*:}" | base64 -d > "/run/coolify/$name"; done; exec coolify-affected evaluate';

    public function __construct(private readonly HelperCapability $capability = new HelperCapability) {}

    public function supports(Server $server): bool
    {
        return $this->capability->compatible($server);
    }

    public function evaluate(Server $server, EvaluateRequest $request, GitAccess $access): EvaluateResponse
    {
        $image = HelperCapability::image();

        try {
            instant_remote_process([
                "docker image inspect {$image} >/dev/null 2>&1 || timeout 600 docker pull {$image} >/dev/null",
            ], $server, throwError: true, timeout: 660, maxAttempts: 1);

            $remoteCommand = ($server->isNonRoot() ? 'sudo ' : '')
                .'docker run --rm -i --network host -v coolify-git-mirrors:/mirrors --entrypoint sh '
                .escapeshellarg($image).' -c '.escapeshellarg(self::CONTAINER_SCRIPT);
            $process = runSshProcess(
                SshMultiplexingHelper::generateSshStdinCommand($server, $remoteCommand, commandTimeout: self::SSH_TIMEOUT_SECONDS),
                self::SSH_TIMEOUT_SECONDS,
                $this->stdin($request, $access),
            );
            SshMultiplexingHelper::noticeHostKeyMismatch($server, $process);
        } catch (Throwable $exception) {
            throw new AffectedRunnerException($this->redact($exception->getMessage(), $request));
        }

        if ($process->exitCode() !== 0) {
            $error = trim($process->errorOutput()) ?: "coolify-affected exited with code {$process->exitCode()}.";

            throw new AffectedRunnerException($this->redact($error, $request));
        }

        return EvaluateResponse::fromJson(trim($process->output()), $request);
    }

    /**
     * Credential files, one `name:base64` line each, then `--`, then the request. They travel on the stdin of
     * SSH, so they are never in a process argument or a file on the host.
     */
    private function stdin(EvaluateRequest $request, GitAccess $access): string
    {
        $lines = '';
        foreach ($access->files as $name => $content) {
            if (preg_match('/\A[a-z_]+\z/', $name) === 1) {
                $lines .= $name.':'.base64_encode($content)."\n";
            }
        }

        return $lines."--\n".$request->toJson()."\n";
    }

    private function redact(string $message, EvaluateRequest $request): string
    {
        foreach ($request->gitConfig as $value) {
            if (preg_match('~://[^:/@]+:([^@]+)@~', $value, $match) === 1) {
                $message = str_replace([$match[1], rawurldecode($match[1])], '***', $message);
            }
            if (preg_match('~Authorization: \S+ (\S+)~', $value, $match) === 1) {
                $message = str_replace($match[1], '***', $message);
            }
        }

        return mb_substr($message, 0, 500);
    }
}
