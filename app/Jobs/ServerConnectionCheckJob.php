<?php

namespace App\Jobs;

use App\Events\ServerReachabilityChanged;
use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use App\Services\ConfigurationRepository;
use App\Services\HetznerService;
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

class ServerConnectionCheckJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const CONNECTION_CHECK_BUFFER = 5;

    private const DOCKER_CHECK_COMMAND_TIMEOUT = 30;

    private const JOB_TIMEOUT_BUFFER = 10;

    private const OVERLAP_LOCK_BUFFER = 10;

    public $tries = 1;

    public $timeout = 65;

    public function __construct(
        public Server $server,
        public bool $disableMux = true
    ) {
        $this->timeout = $this->jobTimeout();
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('server-connection-check-'.$this->server->uuid))
                ->expireAfter($this->timeout + self::OVERLAP_LOCK_BUFFER)
                ->dontRelease(),
        ];
    }

    private function disableSshMux(): void
    {
        $configRepository = app(ConfigurationRepository::class);
        $configRepository->disableSshMux();
    }

    public function handle(): void
    {
        $wasReachable = (bool) $this->server->settings->is_reachable;
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

                return;
            }

            // Check Hetzner server status if applicable
            if ($this->server->hetzner_server_id && $this->server->cloudProviderToken) {
                $this->checkHetznerStatus();
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

                $this->dispatchReachabilityChangedIfNeeded($wasReachable, $wasNotified, false);

                return;
            }

            // Server is reachable, check if Docker is available
            $isUsable = $this->checkDockerAvailability();

            $this->server->settings->update([
                'is_reachable' => true,
                'is_usable' => $isUsable,
            ]);

            if ($this->server->unreachable_count > 0) {
                $this->server->update(['unreachable_count' => 0]);
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

            $this->dispatchReachabilityChangedIfNeeded($wasReachable, $wasNotified, false);

            return;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        if ($exception instanceof TimeoutExceededException) {
            $wasReachable = (bool) $this->server->settings->is_reachable;
            $wasNotified = (bool) $this->server->unreachable_notification_sent;

            $this->server->settings->update([
                'is_reachable' => false,
                'is_usable' => false,
            ]);
            $this->server->increment('unreachable_count');

            $this->dispatchReachabilityChangedIfNeeded($wasReachable, $wasNotified, false);

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

    private function checkHetznerStatus(): void
    {
        $status = null;

        try {
            $hetznerService = new HetznerService($this->server->cloudProviderToken->token);
            $serverData = $hetznerService->getServer($this->server->hetzner_server_id);
            $status = $serverData['status'] ?? null;

        } catch (\Throwable) {
            // Silently ignore — server may have been deleted from Hetzner.
        }
        if ($this->server->hetzner_server_status !== $status) {
            $this->server->update(['hetzner_server_status' => $status]);
            $this->server->hetzner_server_status = $status;
            if ($status === 'off') {
                ray('Server is powered off, marking as unreachable');
                throw new \Exception('Server is powered off');
            }
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

            $timeout = $this->connectionCheckProcessTimeout();
            $sshCommand = SshMultiplexingHelper::generateSshCommand(
                $this->server,
                $commandString,
                true,
                $timeout,
            );
            $process = Process::timeout($timeout)->run($sshCommand);

            return $process->exitCode() === 0;
        } catch (\Throwable $e) {
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
            $commands = ['docker version --format json'];
            if ($this->server->isNonRoot()) {
                $commands = parseCommandsByLineForSudo(collect($commands), $this->server);
            }

            $commandString = implode("\n", $commands);
            $timeout = $this->dockerCheckProcessTimeout();
            $sshCommand = SshMultiplexingHelper::generateSshCommand(
                $this->server,
                $commandString,
                true,
                $timeout,
            );
            $process = Process::timeout($timeout)->run($sshCommand);

            if ($process->exitCode() !== 0) {
                return false;
            }

            // Try to parse the JSON output to ensure Docker is really working
            $output = trim($process->output());
            if (! empty($output)) {
                $dockerInfo = json_decode($output, true);

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

    private function connectionCheckProcessTimeout(): int
    {
        return $this->connectionTimeout() + self::CONNECTION_CHECK_BUFFER;
    }

    private function dockerCheckProcessTimeout(): int
    {
        return $this->connectionTimeout() + self::DOCKER_CHECK_COMMAND_TIMEOUT;
    }

    private function jobTimeout(): int
    {
        $connectionTimeout = $this->connectionTimeout();

        return $connectionTimeout
            + self::CONNECTION_CHECK_BUFFER
            + $connectionTimeout
            + self::DOCKER_CHECK_COMMAND_TIMEOUT
            + self::JOB_TIMEOUT_BUFFER;
    }

    private function connectionTimeout(): int
    {
        return SshMultiplexingHelper::getConnectionTimeout($this->server);
    }
}
