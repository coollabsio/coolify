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

    public string $envContent = '';

    public bool $envFileExists = true;

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
        $this->envContent = '';
        $this->envFileExists = true;

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
                $this->envFileExists = false;
                $this->dispatch('warning', 'Este proyecto no tiene .env');

                return;
            }

            // Store the complete .env content
            $this->envContent = $envContent;

            $this->dispatch('success', 'Archivo .env cargado exitosamente.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error loading .env file: '.$e->getMessage());
        } finally {
            $this->isLoadingEnv = false;
        }
    }


    public function saveEnvFile()
    {
        if (! $this->selectedContainerForEnv) {
            return;
        }

        if (! $this->envFileExists) {
            $this->dispatch('warning', 'Este proyecto no tiene .env');

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

            // Write the complete .env content to container
            $tmpFilename = 'temp/'.uniqid('laravel-env-').'.env';
            Storage::disk('local')->put($tmpFilename, $this->envContent);
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

            $this->dispatch('success', 'Archivo .env guardado exitosamente.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error guardando archivo .env: '.$e->getMessage());
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
            $checkCommand = "docker exec {$escapedContainer} sh -c 'ps aux | grep -E \"schedule:(run|work)\" | grep -v grep || echo notfound'";
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
                $this->schedulerOutput = $supervisorStatus !== '' && $supervisorStatus !== 'notfound'
                    ? $supervisorStatus
                    : 'Scheduler is not running';
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

            $command = "docker exec {$escapedContainer} sh -lc "
                .escapeshellarg(
                    "cd /var/www/html && php artisan optimize:clear >/dev/null 2>&1 || true; "
                    ."php artisan config:clear >/dev/null 2>&1 || true; "
                    ."echo 'Scheduler execution context:'; "
                    .'echo "APP_ENV=${APP_ENV:-}"; '
                    .'echo "CACHE_STORE=${CACHE_STORE:-}"; '
                    .'echo "QUEUE_CONNECTION=${QUEUE_CONNECTION:-}"; '
                    ."php artisan schedule:run --verbose --no-interaction"
                );
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
