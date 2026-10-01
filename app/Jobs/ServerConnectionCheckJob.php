<?php

namespace App\Jobs;

use App\Events\ServerReachabilityChanged;
use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use App\Services\ConfigurationRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

class ServerConnectionCheckJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 60;

    /**
     * Consecutive failed SSH checks before the server is marked unreachable.
     * One failed attempt is often a short network problem, not an offline server.
     */
    public const UNREACHABLE_THRESHOLD = 2;

    /** Each check makes this many SSH attempts before it counts as failed. */
    public const SSH_ATTEMPTS = 2;

    public const SSH_TIMEOUT_SECONDS = 10;

    public const SSH_RETRY_DELAY_SECONDS = 3;

    /** Docker and Compose version commands each get one attempt with this timeout. */
    public const DOCKER_TIMEOUT_SECONDS = 15;

    private ?string $connectionError = null;

    public function __construct(
        public Server $server,
        public bool $disableMux = true
    ) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('server-connection-check-'.$this->server->uuid))->expireAfter($this->timeout + 30)->dontRelease()];
    }

    private function disableSshMux(): void
    {
        $configRepository = app(ConfigurationRepository::class);
        $configRepository->disableSshMux();
    }

    public function handle(): void
    {
        if ($this->server->hasPlaceholderIp()) {
            return;
        }

        $wasReachable = (bool) $this->server->settings->is_reachable;
        $wasUsable = (bool) $this->server->settings->is_usable;
        $wasNotified = (bool) $this->server->unreachable_notification_sent;

        try {
            // Check if server is disabled
            if ($this->server->settings->force_disabled) {
                $this->server->settings->update([
                    'is_reachable' => false,
                    'is_usable' => false,
                ]);
                Log::debug('ServerConnectionCheck: Server is disabled', [
                    'server_id' => $this->server->id,
                    'server_name' => $this->server->name,
                ]);
                $this->logConnectionStateChange($wasReachable, $wasUsable, false, false, 'force_disabled');

                return;
            }

            // Temporarily disable mux if requested
            if ($this->disableMux) {
                $this->disableSshMux();
            }

            // Check basic connectivity first
            $isReachable = $this->checkConnection();

            if (! $isReachable) {
                $this->server->increment('unreachable_count');

                Log::warning('ServerConnectionCheck: Server not reachable', [
                    'server_id' => $this->server->id,
                    'server_name' => $this->server->name,
                    'server_ip' => $this->server->ip,
                    'unreachable_count' => $this->server->unreachable_count,
                ]);

                if ($this->server->unreachable_count < self::UNREACHABLE_THRESHOLD) {
                    return;
                }

                $this->server->settings->update([
                    'is_reachable' => false,
                    'is_usable' => false,
                ]);
                $this->logConnectionStateChange($wasReachable, $wasUsable, false, false, $this->connectionError);

                $this->dispatchReachabilityChangedIfNeeded($wasReachable, $wasNotified, false);

                return;
            }

            // Server is reachable, check if Docker is available
            $isUsable = $this->checkDockerAvailability();

            $this->server->settings->update([
                'is_reachable' => true,
                'is_usable' => $isUsable,
            ]);
            $this->logConnectionStateChange($wasReachable, $wasUsable, true, $isUsable, $isUsable ? null : 'docker_not_available');

            if ($this->server->unreachable_count > 0) {
                // Direct assignment: unreachable_count is not mass-assignable,
                // so update() would silently drop the reset.
                $this->server->unreachable_count = 0;
                $this->server->save();
            }

            $this->dispatchReachabilityChangedIfNeeded($wasReachable, $wasNotified, true);

        } catch (\Throwable $e) {
            // SSH and Docker failures are handled above. An error here (database, cache, a bug)
            // says nothing about the server, so the stored connection state stays as it is.
            Log::error('ServerConnectionCheckJob failed', [
                'error' => $e->getMessage(),
                'server_id' => $this->server->id,
            ]);
        }
    }

    /**
     * Log a change of the state that scheduled jobs use to skip a server, with the node that ran
     * the check. On a multi-node instance this shows when only one node cannot reach a server.
     */
    private function logConnectionStateChange(bool $wasReachable, bool $wasUsable, bool $isReachable, bool $isUsable, ?string $reason): void
    {
        if ($wasReachable === $isReachable && $wasUsable === $isUsable) {
            return;
        }

        Log::channel('scheduled')->info('Server connection state changed', [
            'server_id' => $this->server->id,
            'is_reachable' => $isReachable,
            'is_usable' => $isUsable,
            'was_reachable' => $wasReachable,
            'was_usable' => $wasUsable,
            'unreachable_count' => $this->server->unreachable_count,
            'reason' => $reason,
            'host' => gethostname(),
            'pid' => getmypid(),
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        if ($exception instanceof TimeoutExceededException) {
            // Delete the queue job so it doesn't appear in Horizon's failed list.
            $this->job?->delete();
        }
    }

    /**
     * Fire ServerReachabilityChanged when state crosses UNREACHABLE_THRESHOLD
     * or when a previously-notified server recovers. Skips noise from single transient flaps.
     */
    private function dispatchReachabilityChangedIfNeeded(bool $wasReachable, bool $wasNotified, bool $isReachable): void
    {
        if ($isReachable) {
            if (! $wasReachable || $wasNotified) {
                ServerReachabilityChanged::dispatch($this->server);
            }

            return;
        }

        if ($this->server->unreachable_count >= self::UNREACHABLE_THRESHOLD && ! $wasNotified) {
            ServerReachabilityChanged::dispatch($this->server);
        }
    }

    /**
     * Worst-case run time of one check: every SSH attempt and delay, then the Docker and Compose commands.
     */
    public static function maximumCheckSeconds(): int
    {
        return self::SSH_ATTEMPTS * self::SSH_TIMEOUT_SECONDS
            + (self::SSH_ATTEMPTS - 1) * self::SSH_RETRY_DELAY_SECONDS
            + 2 * self::DOCKER_TIMEOUT_SECONDS;
    }

    /**
     * A short retry inside the check absorbs brief network problems. Longer outages are
     * handled across checks with unreachable_count and UNREACHABLE_THRESHOLD.
     */
    private function checkConnection(): bool
    {
        for ($attempt = 1; $attempt <= self::SSH_ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                Sleep::for(self::SSH_RETRY_DELAY_SECONDS)->seconds();
            }
            if ($this->attemptConnection()) {
                return true;
            }
        }

        return false;
    }

    private function attemptConnection(): bool
    {
        try {
            $process = Process::timeout(self::SSH_TIMEOUT_SECONDS)
                ->run($this->sshCommand('ls -la /', disableMultiplexing: true));
            if ($process->exitCode() !== 0) {
                $this->connectionError = 'ssh exit '.$process->exitCode().': '.Str::limit(trim($process->errorOutput()), 300);
            }

            return $process->exitCode() === 0;
        } catch (\Throwable $e) {
            $this->connectionError = $e->getMessage();
            Log::debug('ServerConnectionCheck: Connection check failed', [
                'server_id' => $this->server->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function checkDockerAvailability(): bool
    {
        try {
            $output = $this->runDockerCommand('docker version --format json');

            if ($output === null) {
                return false;
            }

            // Try to parse the JSON output to ensure Docker is really working
            $output = trim($output);
            if (! empty($output)) {
                $dockerInfo = json_decode($output, true);
                $dockerVersion = dockerEngineVersionFromJson($output);
                if ($dockerVersion !== null) {
                    $this->server->rememberDockerVersion($dockerVersion);
                }

                $composeOutput = $this->runDockerCommand('docker compose version --short');
                $composeVersion = parseDockerEngineVersion($composeOutput);
                if ($composeVersion !== null) {
                    $this->server->rememberComposeVersion($composeVersion);
                }

                return isset($dockerInfo['Server']['Version']);
            }

            return false;
        } catch (\Throwable $e) {
            Log::debug('ServerConnectionCheck: Docker check failed', [
                'server_id' => $this->server->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Run one Docker command with a single attempt. Returns null when it fails or times out.
     */
    private function runDockerCommand(string $command): ?string
    {
        try {
            $process = Process::timeout(self::DOCKER_TIMEOUT_SECONDS)->run($this->sshCommand($command));
        } catch (\Throwable $e) {
            Log::debug('ServerConnectionCheck: Docker command failed', [
                'server_id' => $this->server->id,
                'command' => $command,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $process->successful() ? sanitize_utf8_text(trim($process->output())) : null;
    }

    private function sshCommand(string $command, bool $disableMultiplexing = false): string
    {
        $commands = [$command];
        if ($this->server->isNonRoot()) {
            $commands = parseCommandsByLineForSudo(collect($commands), $this->server);
        }

        return SshMultiplexingHelper::generateSshCommand($this->server, implode("\n", $commands), $disableMultiplexing);
    }
}
