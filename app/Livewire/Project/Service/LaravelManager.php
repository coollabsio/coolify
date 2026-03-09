<?php

namespace App\Livewire\Project\Service;

use App\Models\LocalFileVolume;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

class LaravelManager extends Component
{
    use AuthorizesRequests;

    public Service $service;

    public array $parameters;

    public $applications;

    public $laravelContainers = [];

    public ?int $selectedContainerForEnv = null;

    public ?int $selectedContainerForPhpIni = null;

    public ?int $selectedContainerForCron = null;

    public array $envVariables = [];

    public array $phpIniSettings = [];

    public bool $isLoadingEnv = false;

    public bool $isLoadingPhpIni = false;

    public bool $isLoadingCron = false;

    public bool $isSchedulerEnabled = false;

    public string $schedulerStatus = '';

    public string $schedulerOutput = '';

    public function mount()
    {
        try {
            $this->parameters = get_route_parameters();
            $this->service = Service::whereUuid(request()->route('service_uuid'))->firstOrFail();
            $this->authorize('view', $this->service);
            $this->applications = $this->service->applications->sort();
            $this->detectLaravelContainers();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function detectLaravelContainers()
    {
        $this->laravelContainers = [];
        foreach ($this->applications as $application) {
            if ($this->isLaravelContainer($application)) {
                $containerName = $application->name.'-'.$this->service->uuid;
                $this->laravelContainers[] = [
                    'id' => $application->id,
                    'name' => $application->name,
                    'container_name' => $containerName,
                    'status' => $application->status,
                    'application' => $application,
                ];
            }
        }
    }

    public function isLaravelContainer($application): bool
    {
        // Check if image contains laravel or php
        $image = strtolower($application->image ?? '');
        if (str_contains($image, 'laravel') || str_contains($image, 'php')) {
            return true;
        }

        // Check environment variables
        $envVars = $application->environment_variables()->get();
        foreach ($envVars as $envVar) {
            $key = strtoupper($envVar->key ?? '');
            if (str_contains($key, 'LARAVEL') || str_contains($key, 'APP_KEY') || str_contains($key, 'APP_ENV')) {
                return true;
            }
        }

        // Check if artisan exists (if container is running)
        if (str($application->status)->contains('running')) {
            try {
                $server = $application->service->server;
                $containerName = $application->name.'-'.$this->service->uuid;
                $escapedContainer = escapeshellarg($containerName);
                $command = "docker exec {$escapedContainer} sh -c 'test -f /var/www/html/artisan && echo found || echo notfound'";
                if ($server->isNonRoot()) {
                    $command = "sudo {$command}";
                }
                $output = trim(instant_remote_process([$command], $server, false) ?? '');
                if ($output === 'found') {
                    return true;
                }
            } catch (\Throwable $e) {
                // Continue to next check
            }
        }

        return false;
    }

    public function loadEnvVariables()
    {
        if (! $this->selectedContainerForEnv) {
            return;
        }

        $this->isLoadingEnv = true;
        $this->envVariables = [];

        try {
            $container = collect($this->laravelContainers)->firstWhere('id', $this->selectedContainerForEnv);
            if (! $container) {
                $this->dispatch('error', 'Container not found.');
                $this->isLoadingEnv = false;

                return;
            }

            $application = $container['application'] ?? $this->applications->find($container['id']);
            if (! $application || ! str($application->status)->contains('running')) {
                $this->dispatch('error', 'Container is not running.');
                $this->isLoadingEnv = false;

                return;
            }

            $server = $application->service->server;
            $containerName = $container['container_name'];
            $escapedContainer = escapeshellarg($containerName);

            // Try to read .env file
            $envPath = '/var/www/html/.env';
            $readCommand = "docker exec {$escapedContainer} sh -c 'test -f {$envPath} && cat {$envPath} || echo notfound'";
            if ($server->isNonRoot()) {
                $readCommand = "sudo {$readCommand}";
            }
            $envContent = instant_remote_process([$readCommand], $server, false) ?? '';

            if ($envContent === 'notfound' || empty($envContent)) {
                $this->dispatch('warning', '.env file not found. Creating default .env file...');
                $this->createDefaultEnvFile($server, $escapedContainer, $envPath);
                $envContent = instant_remote_process([$readCommand], $server, false) ?? '';
            }

            // Parse .env content
            $lines = explode("\n", $envContent);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || str_starts_with($line, '#')) {
                    continue;
                }

                if (str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    // Remove quotes
                    $value = trim($value, '"\'');
                    $this->envVariables[$key] = $value;
                }
            }

            // Auto-configure Laravel .env if needed
            $this->autoConfigureLaravelEnv($server, $escapedContainer, $envPath);

            $this->dispatch('success', 'Environment variables loaded successfully.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error loading environment variables: '.$e->getMessage());
        } finally {
            $this->isLoadingEnv = false;
        }
    }

    private function autoConfigureLaravelEnv($server, $escapedContainer, $envPath)
    {
        try {
            $needsUpdate = false;
            $updates = [];

            // Get service environment variables
            $serviceEnvVars = $this->service->environment_variables()->get()->keyBy('key');

            // Auto-configure APP_URL from SERVICE_URL_LARAVEL or FQDN
            if (empty($this->envVariables['APP_URL'] ?? '')) {
                $appUrl = null;
                if ($serviceEnvVars->has('SERVICE_URL_LARAVEL')) {
                    $appUrl = $serviceEnvVars['SERVICE_URL_LARAVEL']->real_value;
                } elseif ($this->service->fqdn) {
                    $appUrl = 'https://'.$this->service->fqdn;
                }
                if ($appUrl) {
                    $this->envVariables['APP_URL'] = $appUrl;
                    $updates['APP_URL'] = $appUrl;
                    $needsUpdate = true;
                }
            }

            // Auto-configure APP_KEY if empty
            if (empty($this->envVariables['APP_KEY'] ?? '')) {
                // Generate APP_KEY using artisan
                $generateKeyCommand = "docker exec {$escapedContainer} php /var/www/html/artisan key:generate --show 2>/dev/null || echo 'failed'";
                if ($server->isNonRoot()) {
                    $generateKeyCommand = "sudo {$generateKeyCommand}";
                }
                $appKey = trim(instant_remote_process([$generateKeyCommand], $server, false) ?? '');
                
                if ($appKey && $appKey !== 'failed' && str_starts_with($appKey, 'base64:')) {
                    $this->envVariables['APP_KEY'] = $appKey;
                    $updates['APP_KEY'] = $appKey;
                    $needsUpdate = true;
                }
            }

            // Auto-configure database variables from service environment
            $dbConfig = [
                'DB_HOST' => 'mariadb',
                'DB_PORT' => '3306',
                'DB_DATABASE' => null,
                'DB_USERNAME' => null,
                'DB_PASSWORD' => null,
            ];

            // Try to get database config from service environment variables
            if ($serviceEnvVars->has('SERVICE_DATABASE_LARAVEL')) {
                $dbConfig['DB_DATABASE'] = $serviceEnvVars['SERVICE_DATABASE_LARAVEL']->real_value;
            }
            if ($serviceEnvVars->has('SERVICE_USER_LARAVEL')) {
                $dbConfig['DB_USERNAME'] = $serviceEnvVars['SERVICE_USER_LARAVEL']->real_value;
            }
            if ($serviceEnvVars->has('SERVICE_PASSWORD_LARAVEL')) {
                $dbConfig['DB_PASSWORD'] = $serviceEnvVars['SERVICE_PASSWORD_LARAVEL']->real_value;
            }

            // Update database config if values are available and not set
            foreach ($dbConfig as $key => $value) {
                if ($value && empty($this->envVariables[$key] ?? '')) {
                    $this->envVariables[$key] = $value;
                    $updates[$key] = $value;
                    $needsUpdate = true;
                }
            }

            // Auto-configure Redis if available
            if ($serviceEnvVars->has('SERVICE_PASSWORD_REDIS') && empty($this->envVariables['REDIS_PASSWORD'] ?? '')) {
                $this->envVariables['REDIS_PASSWORD'] = $serviceEnvVars['SERVICE_PASSWORD_REDIS']->real_value;
                $updates['REDIS_PASSWORD'] = $serviceEnvVars['SERVICE_PASSWORD_REDIS']->real_value;
                $needsUpdate = true;
            }

            // Auto-configure other Laravel defaults
            $defaults = [
                'APP_NAME' => 'Laravel',
                'APP_ENV' => 'production',
                'APP_DEBUG' => 'false',
                'LOG_CHANNEL' => 'stack',
                'LOG_LEVEL' => 'debug',
                'QUEUE_CONNECTION' => 'database',
                'CACHE_DRIVER' => 'file',
                'SESSION_DRIVER' => 'file',
            ];

            foreach ($defaults as $key => $defaultValue) {
                if (empty($this->envVariables[$key] ?? '')) {
                    $this->envVariables[$key] = $defaultValue;
                    $updates[$key] = $defaultValue;
                    $needsUpdate = true;
                }
            }

            // Write updates to .env file if needed
            if ($needsUpdate && ! empty($updates)) {
                $this->writeEnvUpdates($server, $escapedContainer, $envPath, $updates);
                $this->dispatch('success', 'Laravel .env configurado automáticamente con: '.implode(', ', array_keys($updates)));
            }
        } catch (\Throwable $e) {
            $this->dispatch('warning', 'No se pudo configurar automáticamente el .env: '.$e->getMessage());
        }
    }

    private function writeEnvUpdates($server, $escapedContainer, $envPath, $updates)
    {
        // Read current .env
        $readCommand = "docker exec {$escapedContainer} cat {$envPath}";
        if ($server->isNonRoot()) {
            $readCommand = "sudo {$readCommand}";
        }
        $envContent = instant_remote_process([$readCommand], $server, false) ?? '';

        // Update or add variables
        $lines = explode("\n", $envContent);
        $newLines = [];
        $updatedKeys = [];

        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            $updated = false;

            foreach ($updates as $key => $value) {
                if (preg_match('/^'.preg_quote($key, '/').'\s*=/i', $trimmedLine)) {
                    $newLines[] = "{$key}={$value}";
                    $updatedKeys[] = $key;
                    $updated = true;
                    break;
                }
            }

            if (! $updated) {
                $newLines[] = $line;
            }
        }

        // Add new variables that weren't in the file
        foreach ($updates as $key => $value) {
            if (! in_array($key, $updatedKeys)) {
                $newLines[] = "{$key}={$value}";
            }
        }

        $newContent = implode("\n", $newLines);

        // Write back to container
        $tmpFilename = 'temp/'.uniqid('laravel-env-update-').'.env';
        Storage::disk('local')->put($tmpFilename, $newContent);
        $localTmpPath = Storage::disk('local')->path($tmpFilename);

        $serverTmpPath = '/tmp/'.basename($tmpFilename);
        instant_scp($localTmpPath, $serverTmpPath, $server);

        $escapedServerTmp = escapeshellarg($serverTmpPath);
        $copyCommand = "docker cp {$escapedServerTmp} {$escapedContainer}:{$envPath}";
        if ($server->isNonRoot()) {
            $copyCommand = "sudo {$copyCommand}";
        }
        instant_remote_process([$copyCommand], $server);

        Storage::disk('local')->delete($tmpFilename);
        $cleanCommand = "rm -f {$escapedServerTmp}";
        if ($server->isNonRoot()) {
            $cleanCommand = "sudo {$cleanCommand}";
        }
        instant_remote_process([$cleanCommand], $server, false);
    }

    private function createDefaultEnvFile($server, $escapedContainer, $envPath)
    {
        try {
            // Get environment variables from service
            $envVars = $this->service->environment_variables()->get();
            $defaultEnv = [];

            foreach ($envVars as $envVar) {
                $key = $envVar->key;
                $value = $envVar->value ?? '';
                $defaultEnv[] = "{$key}={$value}";
            }

            // Add Laravel defaults
            $requiredKeys = [
                'APP_NAME' => 'Laravel',
                'APP_ENV' => 'production',
                'APP_KEY' => '',
                'APP_DEBUG' => 'false',
                'APP_URL' => $this->service->fqdn ? 'https://'.$this->service->fqdn : 'http://localhost',
                'LOG_CHANNEL' => 'stack',
                'LOG_LEVEL' => 'debug',
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => 'mariadb',
                'DB_PORT' => '3306',
                'QUEUE_CONNECTION' => 'database',
                'CACHE_DRIVER' => 'file',
                'SESSION_DRIVER' => 'file',
            ];

            foreach ($requiredKeys as $key => $defaultValue) {
                $defaultEnv[] = "{$key}={$defaultValue}";
            }

            $envContent = implode("\n", $defaultEnv);

            // Write to container
            $tmpFilename = 'temp/'.uniqid('laravel-env-').'.env';
            Storage::disk('local')->put($tmpFilename, $envContent);
            $localTmpPath = Storage::disk('local')->path($tmpFilename);

            $serverTmpPath = '/tmp/'.basename($tmpFilename);
            instant_scp($localTmpPath, $serverTmpPath, $server);

            $escapedServerTmp = escapeshellarg($serverTmpPath);
            $copyCommand = "docker cp {$escapedServerTmp} {$escapedContainer}:{$envPath}";
            if ($server->isNonRoot()) {
                $copyCommand = "sudo {$copyCommand}";
            }
            instant_remote_process([$copyCommand], $server);

            Storage::disk('local')->delete($tmpFilename);
            $cleanCommand = "rm -f {$escapedServerTmp}";
            if ($server->isNonRoot()) {
                $cleanCommand = "sudo {$cleanCommand}";
            }
            instant_remote_process([$cleanCommand], $server, false);
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to create default .env file: '.$e->getMessage());
        }
    }

    public function updateEnvVariable($key, $value)
    {
        if (! $this->selectedContainerForEnv) {
            return;
        }

        try {
            $container = collect($this->laravelContainers)->firstWhere('id', $this->selectedContainerForEnv);
            if (! $container) {
                $this->dispatch('error', 'Container not found.');

                return;
            }

            $application = $container['application'] ?? $this->applications->find($container['id']);
            if (! $application || ! str($application->status)->contains('running')) {
                $this->dispatch('error', 'Container is not running.');

                return;
            }

            $server = $application->service->server;
            $containerName = $container['container_name'];
            $escapedContainer = escapeshellarg($containerName);
            $envPath = '/var/www/html/.env';

            // Read current .env
            $readCommand = "docker exec {$escapedContainer} cat {$envPath}";
            if ($server->isNonRoot()) {
                $readCommand = "sudo {$readCommand}";
            }
            $envContent = instant_remote_process([$readCommand], $server, false) ?? '';

            // Update or add the variable
            $lines = explode("\n", $envContent);
            $found = false;
            $newLines = [];

            foreach ($lines as $line) {
                $trimmedLine = trim($line);
                if (preg_match('/^'.preg_quote($key, '/').'\s*=/i', $trimmedLine)) {
                    $newLines[] = "{$key}={$value}";
                    $found = true;
                } else {
                    $newLines[] = $line;
                }
            }

            if (! $found) {
                $newLines[] = "{$key}={$value}";
            }

            $newContent = implode("\n", $newLines);

            // Write back to container
            $tmpFilename = 'temp/'.uniqid('laravel-env-update-').'.env';
            Storage::disk('local')->put($tmpFilename, $newContent);
            $localTmpPath = Storage::disk('local')->path($tmpFilename);

            $serverTmpPath = '/tmp/'.basename($tmpFilename);
            instant_scp($localTmpPath, $serverTmpPath, $server);

            $escapedServerTmp = escapeshellarg($serverTmpPath);
            $copyCommand = "docker cp {$escapedServerTmp} {$escapedContainer}:{$envPath}";
            if ($server->isNonRoot()) {
                $copyCommand = "sudo {$copyCommand}";
            }
            instant_remote_process([$copyCommand], $server);

            Storage::disk('local')->delete($tmpFilename);
            $cleanCommand = "rm -f {$escapedServerTmp}";
            if ($server->isNonRoot()) {
                $cleanCommand = "sudo {$cleanCommand}";
            }
            instant_remote_process([$cleanCommand], $server, false);

            // Update local state
            $this->envVariables[$key] = $value;
            $this->dispatch('success', "Environment variable {$key} updated successfully.");
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error updating environment variable: '.$e->getMessage());
        }
    }

    public function loadPhpIniSettings()
    {
        if (! $this->selectedContainerForPhpIni) {
            return;
        }

        $this->isLoadingPhpIni = true;
        $this->phpIniSettings = [];

        try {
            $container = collect($this->laravelContainers)->firstWhere('id', $this->selectedContainerForPhpIni);
            if (! $container) {
                $this->dispatch('error', 'Container not found.');
                $this->isLoadingPhpIni = false;

                return;
            }

            $application = $container['application'] ?? $this->applications->find($container['id']);
            if (! $application || ! str($application->status)->contains('running')) {
                $this->dispatch('error', 'Container is not running.');
                $this->isLoadingPhpIni = false;

                return;
            }

            $server = $application->service->server;
            $containerName = $container['container_name'];
            $escapedContainer = escapeshellarg($containerName);

            // Get PHP settings
            $settings = ['upload_max_filesize', 'post_max_size', 'max_execution_time', 'memory_limit', 'max_input_vars'];
            foreach ($settings as $setting) {
                $command = "docker exec {$escapedContainer} php -r \"echo ini_get('{$setting}');\"";
                if ($server->isNonRoot()) {
                    $command = "sudo {$command}";
                }
                $value = trim(instant_remote_process([$command], $server, false) ?? '');
                $this->phpIniSettings[$setting] = $value ?: 'Not set';
            }

            $this->dispatch('success', 'PHP settings loaded successfully.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error loading PHP settings: '.$e->getMessage());
        } finally {
            $this->isLoadingPhpIni = false;
        }
    }

    public function updatePhpIniSetting($setting, $value)
    {
        // Similar to WordPressManager - simplified version
        $this->dispatch('info', 'PHP INI update functionality coming soon. Use the File Explorer to edit php.ini directly.');
    }

    public function checkSchedulerStatus()
    {
        if (! $this->selectedContainerForCron) {
            return;
        }

        $this->isLoadingCron = true;
        $this->schedulerStatus = '';
        $this->schedulerOutput = '';

        try {
            $container = collect($this->laravelContainers)->firstWhere('id', $this->selectedContainerForCron);
            if (! $container) {
                $this->dispatch('error', 'Container not found.');
                $this->isLoadingCron = false;

                return;
            }

            $application = $container['application'] ?? $this->applications->find($container['id']);
            if (! $application || ! str($application->status)->contains('running')) {
                $this->dispatch('error', 'Container is not running.');
                $this->isLoadingCron = false;

                return;
            }

            $server = $application->service->server;
            $containerName = $container['container_name'];
            $escapedContainer = escapeshellarg($containerName);

            // Check if scheduler process is running
            $checkCommand = "docker exec {$escapedContainer} sh -c 'ps aux | grep \"schedule:run\" | grep -v grep || echo notfound'";
            if ($server->isNonRoot()) {
                $checkCommand = "sudo {$checkCommand}";
            }
            $processCheck = trim(instant_remote_process([$checkCommand], $server, false) ?? '');

            // Check supervisor status
            $supervisorCommand = "docker exec {$escapedContainer} sh -c 'supervisorctl status scheduler 2>/dev/null || echo notfound'";
            if ($server->isNonRoot()) {
                $supervisorCommand = "sudo {$supervisorCommand}";
            }
            $supervisorStatus = trim(instant_remote_process([$supervisorCommand], $server, false) ?? '');

            if ($processCheck !== 'notfound' || str_contains($supervisorStatus, 'RUNNING')) {
                $this->isSchedulerEnabled = true;
                $this->schedulerStatus = 'Running';
                $this->schedulerOutput = $supervisorStatus ?: 'Scheduler process is running';
            } else {
                $this->isSchedulerEnabled = false;
                $this->schedulerStatus = 'Stopped';
                $this->schedulerOutput = 'Scheduler is not running';
            }

            $this->dispatch('success', 'Scheduler status checked.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error checking scheduler status: '.$e->getMessage());
        } finally {
            $this->isLoadingCron = false;
        }
    }

    public function toggleScheduler()
    {
        if (! $this->selectedContainerForCron) {
            return;
        }

        try {
            $container = collect($this->laravelContainers)->firstWhere('id', $this->selectedContainerForCron);
            if (! $container) {
                $this->dispatch('error', 'Container not found.');

                return;
            }

            $application = $container['application'] ?? $this->applications->find($container['id']);
            if (! $application || ! str($application->status)->contains('running')) {
                $this->dispatch('error', 'Container is not running.');

                return;
            }

            $server = $application->service->server;
            $containerName = $container['container_name'];
            $escapedContainer = escapeshellarg($containerName);

            if ($this->isSchedulerEnabled) {
                // Stop scheduler
                $command = "docker exec {$escapedContainer} supervisorctl stop scheduler";
                if ($server->isNonRoot()) {
                    $command = "sudo {$command}";
                }
                instant_remote_process([$command], $server, false);
                $this->isSchedulerEnabled = false;
                $this->schedulerStatus = 'Stopped';
                $this->dispatch('success', 'Scheduler stopped.');
            } else {
                // Start scheduler
                $command = "docker exec {$escapedContainer} supervisorctl start scheduler";
                if ($server->isNonRoot()) {
                    $command = "sudo {$command}";
                }
                instant_remote_process([$command], $server, false);
                $this->isSchedulerEnabled = true;
                $this->schedulerStatus = 'Running';
                $this->dispatch('success', 'Scheduler started.');
            }

            // Refresh status
            $this->checkSchedulerStatus();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error toggling scheduler: '.$e->getMessage());
        }
    }

    public function runScheduler()
    {
        if (! $this->selectedContainerForCron) {
            return;
        }

        try {
            $container = collect($this->laravelContainers)->firstWhere('id', $this->selectedContainerForCron);
            if (! $container) {
                $this->dispatch('error', 'Container not found.');

                return;
            }

            $application = $container['application'] ?? $this->applications->find($container['id']);
            if (! $application || ! str($application->status)->contains('running')) {
                $this->dispatch('error', 'Container is not running.');

                return;
            }

            $server = $application->service->server;
            $containerName = $container['container_name'];
            $escapedContainer = escapeshellarg($containerName);

            $command = "docker exec {$escapedContainer} php /var/www/html/artisan schedule:run --verbose";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }
            $output = instant_remote_process([$command], $server, false) ?? '';
            $this->schedulerOutput = $output;
            $this->dispatch('success', 'Scheduler executed successfully.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error running scheduler: '.$e->getMessage());
            $this->schedulerOutput = $e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.project.service.laravel-manager');
    }
}
