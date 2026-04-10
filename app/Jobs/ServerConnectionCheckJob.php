<?php

namespace App\Jobs;

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

    public $tries = 1;

    public $timeout = 15;

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

    public function handle()
    {
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

            return;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        if ($exception instanceof TimeoutExceededException) {
            $this->server->settings->update([
                'is_reachable' => false,
                'is_usable' => false,
            ]);
            $this->server->increment('unreachable_count');

            // Delete the queue job so it doesn't appear in Horizon's failed list.
            $this->job?->delete();
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

            $sshCommand = SshMultiplexingHelper::generateSshCommand($this->server, $commandString, true);
            $process = Process::timeout(10)->run($sshCommand);

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
