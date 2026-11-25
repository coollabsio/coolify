<?php

namespace App\Jobs;

use App\Actions\Server\ProcessSentinelReport;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pull mode: Sentinel does not push (PUSH_ENABLED=false), so Coolify reads the same report over SSH
 * and handles it like a push. For servers that cannot reach Coolify, for example when Coolify runs on a private network.
 */
class PullSentinelReportJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const URL = 'http://localhost:8888/api/push-payload';

    public $tries = 1;

    public $timeout = 30;

    public function __construct(public Server $server) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('sentinel-pull-'.$this->server->uuid))->expireAfter(30)->dontRelease()];
    }

    public function handle(): void
    {
        if (! $this->server->isFunctional()) {
            return;
        }

        try {
            [$status, $body] = $this->fetch();
        } catch (\Throwable $e) {
            $this->server->rememberSentinelPushProblem('Coolify could not read the Sentinel report over SSH: '.Str::limit(trim($e->getMessage()), 200));

            return;
        }

        if ($status === 404) {
            $this->server->rememberSentinelPushProblem('Sentinel is too old for pull mode. Update Sentinel.');

            return;
        }

        $report = json_decode($body, true);
        if ($status !== 200) {
            $reason = is_array($report) && is_string($report['error'] ?? null) ? $report['error'] : "HTTP {$status}";
            $this->server->rememberSentinelPushProblem('Sentinel refused the pull request: '.Str::limit($reason, 200));

            return;
        }

        if (! is_array($report) || ! is_array($report['payload'] ?? null)) {
            $this->server->rememberSentinelPushProblem('Sentinel returned a report that is not valid JSON.');

            return;
        }

        try {
            ProcessSentinelReport::run($this->server, $report['payload'], is_string($report['version'] ?? null) ? $report['version'] : null);
        } catch (ValidationException) {
            $this->server->rememberSentinelPushProblem('Sentinel returned a report without a containers list.');
        }
    }

    /**
     * The token reaches curl on stdin, so it is not a process argument on the server
     * (see SentinelMetricsClient::remoteFetch()). curl prints the HTTP status on the last line.
     *
     * @return array{0: int, 1: string}
     */
    private function fetch(): array
    {
        $token = $this->server->settings->ensureValidSentinelToken();
        $curl = "curl -sS --connect-timeout 3 --max-time 10 -w '\\n%{http_code}' -H @- \"".self::URL."\" <<'COOLIFY_SENTINEL_AUTH'\nAuthorization: Bearer {$token}\nCOOLIFY_SENTINEL_AUTH";

        $output = (string) instant_remote_process(
            ["docker exec -i coolify-sentinel sh -s <<'COOLIFY_SENTINEL_SCRIPT'\n{$curl}\nCOOLIFY_SENTINEL_SCRIPT"],
            $this->server,
            timeout: 20,
            // The next pull follows within a minute, and a retry could outlast the job timeout.
            maxAttempts: 1,
        );

        $body = Str::beforeLast($output, "\n");
        $status = Str::afterLast($output, "\n");

        return [(int) $status, $body === $output ? '' : $body];
    }
}
