<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class LaravelCron extends Component
{
    use AuthorizesRequests;

    public Service $service;

    public array $parameters;

    public $applications;

    public array $laravelContainers = [];

    public ?int $selectedContainerForCron = null;

    public bool $isLoadingCron = false;

    public bool $isSchedulerEnabled = false;

    public string $schedulerStatus = '';

    public string $schedulerOutput = '';

    public bool $isLoadingScheduleList = false;

    /** @var array<int, array{command: string, expression: string, next_due: string, last_run: string, description: string}> */
    public array $scheduledTasks = [];

    public function mount(): void
    {
        $this->parameters = get_route_parameters();
        $this->service = Service::whereUuid(request()->route('service_uuid'))->firstOrFail();
        $this->authorize('view', $this->service);
        $this->applications = $this->service->applications->sort();
        $this->detectLaravelContainers();

        // Cron UI should focus only on Laravel containers (no phpMyAdmin).
        // Default to the first running Laravel container if available.
        if ($this->selectedContainerForCron === null && ! empty($this->laravelContainers)) {
            $this->selectedContainerForCron = (int) ($this->laravelContainers[0]['id'] ?? null);
        }

        if ($this->selectedContainerForCron) {
            $this->loadCronData();
        }
    }

    public function detectLaravelContainers(): void
    {
        $this->laravelContainers = [];

        foreach ($this->applications as $application) {
            if (! $this->isLaravelContainer($application)) {
                continue;
            }

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

    public function isLaravelContainer($application): bool
    {
        // Primary heuristic (cheap): image/env suggests Laravel.
        $image = strtolower($application->image ?? '');
        $looksLikeLaravel = str_contains($image, 'laravel') || str_contains($image, 'php');

        $envVars = $application->environment_variables()->get();
        foreach ($envVars as $envVar) {
            $key = strtoupper($envVar->key ?? '');
            if (str_contains($key, 'LARAVEL') || str_contains($key, 'APP_KEY') || str_contains($key, 'APP_ENV')) {
                $looksLikeLaravel = true;
                break;
            }
        }

        if (! $looksLikeLaravel) {
            return false;
        }

        // Strong check: artisan must exist in the container.
        if (! str($application->status)->contains('running')) {
            return false;
        }

        $server = $application->service->server;
        $containerName = $application->name.'-'.$this->service->uuid;
        $escapedContainer = escapeshellarg($containerName);

        $command = "docker exec {$escapedContainer} sh -c 'test -f /var/www/html/artisan && echo found || echo notfound'";
        if ($server->isNonRoot()) {
            $command = "sudo {$command}";
        }

        $output = trim(instant_remote_process([$command], $server, false) ?? '');

        return $output === 'found';
    }

    private function getSelectedContainerApplicationContext(): ?array
    {
        if (! $this->selectedContainerForCron) {
            return null;
        }

        $container = collect($this->laravelContainers)->firstWhere('id', $this->selectedContainerForCron);
        if (! $container) {
            return null;
        }

        $application = $container['application'] ?? $this->applications->find($container['id']);
        if (! $application || ! str($application->status)->contains('running')) {
            return null;
        }

        $server = $application->service->server;
        $escapedContainer = escapeshellarg($container['container_name']);

        return [
            'container' => $container,
            'application' => $application,
            'server' => $server,
            'escapedContainer' => $escapedContainer,
        ];
    }

    public function loadCronData(): void
    {
        $this->checkSchedulerStatus();
        $this->loadScheduleList();
    }

    public function checkSchedulerStatus(): void
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

                return;
            }

            $application = $container['application'] ?? $this->applications->find($container['id']);
            if (! $application || ! str($application->status)->contains('running')) {
                $this->dispatch('error', 'Container is not running.');

                return;
            }

            $server = $application->service->server;
            $escapedContainer = escapeshellarg($container['container_name']);

            $checkCommand = "docker exec {$escapedContainer} sh -c 'ps aux | grep \"schedule:run\" | grep -v grep || echo notfound'";
            if ($server->isNonRoot()) {
                $checkCommand = "sudo {$checkCommand}";
            }
            $processCheck = trim(instant_remote_process([$checkCommand], $server, false) ?? '');

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
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error checking scheduler status: '.$e->getMessage());
        } finally {
            $this->isLoadingCron = false;
        }
    }

    public function loadScheduleList(): void
    {
        if (! $this->selectedContainerForCron) {
            return;
        }

        $this->isLoadingScheduleList = true;
        $this->scheduledTasks = [];

        try {
            $context = $this->getSelectedContainerApplicationContext();
            if (! $context) {
                $this->dispatch('error', 'Container not found or not running.');

                return;
            }

            $server = $context['server'];
            $escapedContainer = $context['escapedContainer'];

            // Prefer json if supported; fallback to plain table.
            $jsonCommand = "docker exec {$escapedContainer} php /var/www/html/artisan schedule:list --format=json";
            $rawJson = '';
            if ($server->isNonRoot()) {
                $jsonCommand = "sudo {$jsonCommand}";
            }
            $rawJson = (string) (instant_remote_process([$jsonCommand], $server, false) ?? '');
            $rawJson = trim($rawJson);
            $parsed = $this->parseScheduleListOutput($rawJson);

            if ($parsed === []) {
                // Some Laravel versions use --json instead.
                $jsonCommand = "docker exec {$escapedContainer} php /var/www/html/artisan schedule:list --json";
                if ($server->isNonRoot()) {
                    $jsonCommand = "sudo {$jsonCommand}";
                }
                $rawJson = (string) (instant_remote_process([$jsonCommand], $server, false) ?? '');
                $rawJson = trim($rawJson);
                $parsed = $this->parseScheduleListOutput($rawJson);
            }

            if ($parsed !== []) {
                $this->scheduledTasks = $parsed;

                return;
            }

            $plainCommand = "docker exec {$escapedContainer} php /var/www/html/artisan schedule:list";
            if ($server->isNonRoot()) {
                $plainCommand = "sudo {$plainCommand}";
            }

            $rawPlain = (string) (instant_remote_process([$plainCommand], $server, false) ?? '');
            $rawPlain = trim($rawPlain);

            $this->scheduledTasks = $this->parseScheduleListOutput($rawPlain);
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error loading schedule list: '.$e->getMessage());
        } finally {
            $this->isLoadingScheduleList = false;
        }
    }

    /**
     * @return array<int, array{command: string, expression: string, next_due: string, last_run: string, description: string}>
     */
    private function parseScheduleListOutput(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        // If json is available, prefer it. Laravel supports `--format=json` in some versions.
        $decoded = null;
        if (str_starts_with($raw, '{') || str_starts_with($raw, '[')) {
            $decoded = json_decode($raw, true);
        }

        if (is_array($decoded)) {
            $list = $decoded['tasks'] ?? $decoded['data'] ?? $decoded;
            if (is_array($list)) {
                $result = [];
                foreach ($list as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $result[] = [
                        'command' => (string) ($item['command'] ?? ''),
                        'expression' => (string) ($item['expression'] ?? ''),
                        'next_due' => (string) ($item['next_due'] ?? $item['nextDue'] ?? ''),
                        'last_run' => (string) ($item['last_run'] ?? $item['lastRun'] ?? ''),
                        'description' => (string) ($item['description'] ?? ''),
                    ];
                }

                return $result;
            }
        }

        // Plain output fallback: Symfony table usually contains `|` separators.
        $lines = preg_split('/\r?\n/', $raw);
        $header = null;
        $headerIndex = [];
        $rows = [];
        $columnCount = null;

        foreach ($lines as $line) {
            if (strpos($line, '|') === false) {
                continue;
            }

            $trim = trim($line);
            if ($trim === '' || preg_match('/^\+[-+]+\+$/', $trim) === 1) {
                continue;
            }

            // Tokenize `| col | col |`
            $parts = array_map('trim', explode('|', trim($trim, "| ")));
            $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));

            if ($columnCount === null) {
                $columnCount = count($parts);
            }

            if ($header === null) {
                $candidate = strtolower(implode(' ', $parts));
                if (str_contains($candidate, 'command') && str_contains($candidate, 'expression')) {
                    $header = $parts;
                    foreach ($header as $i => $name) {
                        $headerIndex[strtolower($name)] = $i;
                    }
                    continue;
                }
            } else {
                if ($columnCount !== count($parts)) {
                    continue;
                }

                if (count($parts) < 2) {
                    continue;
                }

                $rows[] = $parts;
            }
        }

        if ($header === null || $rows === []) {
            return [];
        }

        $result = [];
        foreach ($rows as $cols) {
            $command = '';
            foreach (['command', 'action'] as $name) {
                if (array_key_exists(strtolower($name), $headerIndex)) {
                    $command = (string) ($cols[$headerIndex[strtolower($name)]] ?? '');
                }
            }

            $expression = (string) ($cols[$headerIndex['expression'] ?? -1] ?? '');
            $nextDue = (string) ($cols[$headerIndex['next due'] ?? $headerIndex['next_due'] ?? -1] ?? '');
            $lastRun = (string) ($cols[$headerIndex['last run'] ?? $headerIndex['last_run'] ?? -1] ?? '');
            $description = (string) ($cols[$headerIndex['description'] ?? $headerIndex['desc'] ?? -1] ?? '');

            if ($command !== '') {
                $result[] = [
                    'command' => $command,
                    'expression' => $expression,
                    'next_due' => $nextDue,
                    'last_run' => $lastRun,
                    'description' => $description,
                ];
            }
        }

        return $result;
    }

    public function executeTaskNow(int $index): void
    {
        if (! isset($this->scheduledTasks[$index])) {
            $this->dispatch('error', 'Task not found.');

            return;
        }

        $taskCommand = (string) data_get($this->scheduledTasks[$index], 'command', '');
        $taskCommand = trim($taskCommand);
        if ($taskCommand === '') {
            $this->dispatch('error', 'Task command is empty.');

            return;
        }

        try {
            $context = $this->getSelectedContainerApplicationContext();
            if (! $context) {
                $this->dispatch('error', 'Container not found or not running.');

                return;
            }

            $server = $context['server'];
            $escapedContainer = $context['escapedContainer'];

            $tokens = preg_split('/\s+/', $taskCommand) ?: [];
            $tokens = array_values(array_filter($tokens, fn ($t) => $t !== ''));
            foreach ($tokens as $token) {
                if (preg_match('/[;&|`$<>\\\\]/', (string) $token)) {
                    $this->dispatch('error', 'Invalid task command.');

                    return;
                }
            }

            $artisanArgs = implode(' ', array_map('escapeshellarg', $tokens));
            $command = "docker exec {$escapedContainer} php /var/www/html/artisan {$artisanArgs}";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }

            $this->schedulerOutput = (string) (instant_remote_process([$command], $server, false) ?? '');
            $this->dispatch('success', 'Task executed successfully.');
        } catch (\Throwable $e) {
            $this->schedulerOutput = $e->getMessage();
            $this->dispatch('error', 'Error executing task: '.$e->getMessage());
        }
    }

    public function toggleScheduler(): void
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
            $escapedContainer = escapeshellarg($container['container_name']);

            if ($this->isSchedulerEnabled) {
                $command = "docker exec {$escapedContainer} supervisorctl stop scheduler";
                if ($server->isNonRoot()) {
                    $command = "sudo {$command}";
                }
                instant_remote_process([$command], $server, false);
                $this->isSchedulerEnabled = false;
                $this->schedulerStatus = 'Stopped';
            } else {
                $command = "docker exec {$escapedContainer} supervisorctl start scheduler";
                if ($server->isNonRoot()) {
                    $command = "sudo {$command}";
                }
                instant_remote_process([$command], $server, false);
                $this->isSchedulerEnabled = true;
                $this->schedulerStatus = 'Running';
            }

            $this->checkSchedulerStatus();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error toggling scheduler: '.$e->getMessage());
        }
    }

    public function runScheduler(): void
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
            $escapedContainer = escapeshellarg($container['container_name']);

            $command = "docker exec {$escapedContainer} php /var/www/html/artisan schedule:run --verbose";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }
            $this->schedulerOutput = (string) (instant_remote_process([$command], $server, false) ?? '');
            $this->dispatch('success', 'Scheduler executed successfully.');
        } catch (\Throwable $e) {
            $this->schedulerOutput = $e->getMessage();
            $this->dispatch('error', 'Error running scheduler: '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.project.service.laravel-cron');
    }
}

