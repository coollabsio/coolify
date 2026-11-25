<?php

namespace App\Services\DeployTriggers;

use App\Models\Server;
use App\Services\DeployTriggers\Contract\EvaluateRequest;
use App\Services\DeployTriggers\Contract\EvaluateResponse;
use RuntimeException;

/**
 * Runs `coolify-affected evaluate` in a short-lived helper container on a server of the team.
 */
class SshAffectedRunner implements AffectedRunner
{
    public const SSH_TIMEOUT_SECONDS = 180;

    public function __construct(private readonly HelperCapability $capability = new HelperCapability) {}

    public function supports(Server $server): bool
    {
        return $this->capability->compatible($server);
    }

    public function evaluate(Server $server, EvaluateRequest $request, GitAccess $access): EvaluateResponse
    {
        try {
            $output = instant_remote_process([$this->script($request, $access)], $server, throwError: true, timeout: self::SSH_TIMEOUT_SECONDS);
        } catch (RuntimeException $exception) {
            throw new AffectedRunnerException($this->redact($exception->getMessage(), $request), previous: null);
        }

        return EvaluateResponse::fromJson((string) $output, $request);
    }

    /**
     * One `sh -c` line, so the sudo parser of non-root servers keeps it whole.
     */
    private function script(EvaluateRequest $request, GitAccess $access): string
    {
        $image = HelperCapability::image();
        $directory = '/tmp/coolify-eval-'.new_public_id();
        $lines = ['umask 077', "mkdir -p {$directory}", "trap \"rm -rf {$directory}\" EXIT"];
        foreach ($access->files as $name => $content) {
            if (preg_match('/\A[a-z_]+\z/', $name) !== 1) {
                continue;
            }
            $lines[] = 'echo '.base64_encode($content)." | base64 -d > {$directory}/{$name}";
        }
        $lines[] = "docker image inspect {$image} >/dev/null 2>&1 || timeout 600 docker pull {$image} >/dev/null";
        $lines[] = 'echo '.base64_encode($request->toJson())." | base64 -d | docker run --rm -i --network host -v coolify-git-mirrors:/mirrors -v {$directory}:/run/coolify:ro {$image} coolify-affected evaluate";

        return "sh -c '".implode('; ', $lines)."'";
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
