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
use Illuminate\Support\Str;

class ServerConnectionCheckJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 15;

    private ?string $connectionError = null;

    public function __construct(
        public Server $server,
        public bool $disableMux = true
    ) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('server-connection-check-'.$this->server->uuid))->expireAfter(25)->dontRelease()];
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
                $this->server->settings->update([
                    'is_reachable' => false,
                    'is_usable' => false,
                ]);
                $this->server->increment('unreachable_count');

                Log::warning('ServerConnectionCheck: Server not reachable', [
                    'server_id' => $this->server->id,
                    'server_name' => $this->server->name,
                    'server_ip' => $this->server->ip,
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

            Log::error('ServerConnectionCheckJob failed', [
                'error' => $e->getMessage(),
                'server_id' => $this->server->id,
            ]);
            $this->server->settings->update([
                'is_reachable' => false,
                'is_usable' => false,
            ]);
            $this->server->increment('unreachable_count');
            $this->logConnectionStateChange($wasReachable, $wasUsable, false, false, $e->getMessage());

            $this->dispatchReachabilityChangedIfNeeded($wasReachable, $wasNotified, false);

            return;
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
     * Fire ServerReachabilityChanged when state crosses the unreachable threshold (count >= 2)
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

        if ($this->server->unreachable_count >= 2 && ! $wasNotified) {
            ServerReachabilityChanged::dispatch($this->server);
        }
    }

    private function checkConnection(): bool
    {
        try {
            // Single SSH attempt without SshRetryHandler — retries waste time for connectivity checks.
            // Backoff is managed at the dispatch level via unreachable_count.
            $commands = ['ls -la /'];
            if ($this->server->isNonRoot()) {
                $commands = parseCommandsByLineForSudo(collect($commands), $this->server);
            }
            $commandString = implode("\n", $commands);

            $sshCommand = SshMultiplexingHelper::generateSshCommand($this->server, $commandString, true);
            $process = Process::timeout(10)->run($sshCommand);
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
            // Use instant_remote_process to check Docker
            // The function will automatically handle sudo for non-root users
            $output = instant_remote_process_with_timeout(
                ['docker version --format json'],
                $this->server,
                false // don't throw error
            );

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

                $composeOutput = instant_remote_process_with_timeout(
                    ['docker compose version --short'],
                    $this->server,
                    false
                );
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
}
