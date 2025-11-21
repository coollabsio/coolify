<?php

namespace App\Jobs;

use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Events\ServerReachabilityChanged;
use App\Events\ServerValidated;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ValidateAndInstallServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes

    public int $maxTries = 3;

    public function __construct(
        public Server $server,
        public int $numberOfTries = 0
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        try {
            // Mark validation as in progress
            $this->server->update(['is_validating' => true]);

            Log::info('ValidateAndInstallServer: Starting validation', [
                'server_id' => $this->server->id,
                'server_name' => $this->server->name,
                'attempt' => $this->numberOfTries + 1,
            ]);

            // Validate connection
            ['uptime' => $uptime, 'error' => $error] = $this->server->validateConnection();
            if (! $uptime) {
                $errorMessage = 'Server is not reachable. Please validate your configuration and connection.<br>Check this <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/openssh">documentation</a> for further help. <br><br>Error: '.$error;
                $this->server->update([
                    'validation_logs' => $errorMessage,
                    'is_validating' => false,
                ]);
                Log::error('ValidateAndInstallServer: Server not reachable', [
                    'server_id' => $this->server->id,
                    'error' => $error,
                ]);

                return;
            }

            // Validate OS
            $supportedOsType = $this->server->validateOS();
            if (! $supportedOsType) {
                $errorMessage = 'Server OS type is not supported. Please install Docker manually before continuing: <a target="_blank" class="underline" href="https://docs.docker.com/engine/install/#server">documentation</a>.';
                $this->server->update([
                    'validation_logs' => $errorMessage,
                    'is_validating' => false,
                ]);
                Log::error('ValidateAndInstallServer: OS not supported', [
                    'server_id' => $this->server->id,
                ]);

                return;
            }

            // Check and install prerequisites
            $validationResult = $this->server->validatePrerequisites();
            if (! $validationResult['success']) {
                if ($this->numberOfTries >= $this->maxTries) {
                    $missingCommands = implode(', ', $validationResult['missing']);
                    $errorMessage = "Prerequisites ({$missingCommands}) could not be installed after {$this->maxTries} attempts. Please install them manually before continuing.";
                    $this->server->update([
                        'validation_logs' => $errorMessage,
                        'is_validating' => false,
                    ]);
                    Log::error('ValidateAndInstallServer: Prerequisites installation failed after max tries', [
                        'server_id' => $this->server->id,
                        'attempts' => $this->numberOfTries,
                        'missing_commands' => $validationResult['missing'],
                        'found_commands' => $validationResult['found'],
                    ]);

                    return;
                }

                Log::info('ValidateAndInstallServer: Installing prerequisites', [
                    'server_id' => $this->server->id,
                    'attempt' => $this->numberOfTries + 1,
                    'missing_commands' => $validationResult['missing'],
                    'found_commands' => $validationResult['found'],
                ]);

                // Install prerequisites
                $this->server->installPrerequisites();

                // Retry validation after installation
                self::dispatch($this->server, $this->numberOfTries + 1)->delay(now()->addSeconds(30));

                return;
            }

            // Check if Docker is installed
            $dockerInstalled = $this->server->validateDockerEngine();
            $dockerComposeInstalled = $this->server->validateDockerCompose();

            if (! $dockerInstalled || ! $dockerComposeInstalled) {
                // Try to install Docker
                if ($this->numberOfTries >= $this->maxTries) {
                    $errorMessage = 'Docker Engine could not be installed after '.$this->maxTries.' attempts. Please install Docker manually before continuing: <a target="_blank" class="underline" href="https://docs.docker.com/engine/install/#server">documentation</a>.';
                    $this->server->update([
                        'validation_logs' => $errorMessage,
                        'is_validating' => false,
                    ]);
                    Log::error('ValidateAndInstallServer: Docker installation failed after max tries', [
                        'server_id' => $this->server->id,
                        'attempts' => $this->numberOfTries,
                    ]);

                    return;
                }

                Log::info('ValidateAndInstallServer: Installing Docker', [
                    'server_id' => $this->server->id,
                    'attempt' => $this->numberOfTries + 1,
                ]);

                // Install Docker
                $this->server->installDocker();

                // Retry validation after installation
                self::dispatch($this->server, $this->numberOfTries + 1)->delay(now()->addSeconds(30));

                return;
            }

            // Validate Docker version
            $dockerVersion = $this->server->validateDockerEngineVersion();
            if (! $dockerVersion) {
                $requiredDockerVersion = str(config('constants.docker.minimum_required_version'))->before('.');
                $errorMessage = 'Minimum Docker Engine version '.$requiredDockerVersion.' is not installed. Please install Docker manually before continuing: <a target="_blank" class="underline" href="https://docs.docker.com/engine/install/#server">documentation</a>.';
                $this->server->update([
                    'validation_logs' => $errorMessage,
                    'is_validating' => false,
                ]);
                Log::error('ValidateAndInstallServer: Docker version not sufficient', [
                    'server_id' => $this->server->id,
                ]);

                return;
            }

            // Validation successful!
            Log::info('ValidateAndInstallServer: Validation successful', [
                'server_id' => $this->server->id,
                'server_name' => $this->server->name,
            ]);

            // Start proxy if needed
            if (! $this->server->isBuildServer()) {
                $proxyShouldRun = CheckProxy::run($this->server, true);
                if ($proxyShouldRun) {
                    StartProxy::dispatch($this->server);
                }
            }

            // Mark validation as complete
            $this->server->update(['is_validating' => false]);

            // Refresh server to get latest state
            $this->server->refresh();

            // Broadcast events to update UI
            ServerValidated::dispatch($this->server->team_id, $this->server->uuid);
            ServerReachabilityChanged::dispatch($this->server);

        } catch (\Throwable $e) {
            Log::error('ValidateAndInstallServer: Exception occurred', [
                'server_id' => $this->server->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->server->update([
                'validation_logs' => 'An error occurred during validation: '.$e->getMessage(),
                'is_validating' => false,
            ]);
        }
    }
}
