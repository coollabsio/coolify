<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class LaravelArtisan extends Component
{
    use AuthorizesRequests;

    public Service $service;

    public array $parameters;

    public $applications;

    public array $laravelContainers = [];

    public ?int $selectedContainer = null;

    public bool $isLoadingCommands = false;

    /** @var array<int, array{name: string, description: string}> */
    public array $artisanCommands = [];

    public ?string $selectedCommand = null;

    public string $selectedCommandDescription = '';

    public bool $isLoadingHelp = false;

    public string $selectedCommandHelp = '';

    public string $output = '';

    public bool $isRunning = false;

    public function mount(): void
    {
        $this->parameters = get_route_parameters();
        $this->service = Service::whereUuid(request()->route('service_uuid'))->firstOrFail();
        $this->authorize('view', $this->service);
        $this->applications = $this->service->applications->sort();
        $this->detectLaravelContainers();
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
        $image = strtolower($application->image ?? '');
        if (str_contains($image, 'laravel') || str_contains($image, 'php')) {
            return true;
        }

        $envVars = $application->environment_variables()->get();
        foreach ($envVars as $envVar) {
            $key = strtoupper($envVar->key ?? '');
            if (str_contains($key, 'LARAVEL') || str_contains($key, 'APP_KEY') || str_contains($key, 'APP_ENV')) {
                return true;
            }
        }

        return false;
    }

    private function getSelectedContainerContext(): ?array
    {
        if (! $this->selectedContainer) {
            return null;
        }

        $container = collect($this->laravelContainers)->firstWhere('id', $this->selectedContainer);
        if (! $container) {
            return null;
        }

        $application = $container['application'] ?? $this->applications->find($container['id']);
        if (! $application || ! str($application->status)->contains('running')) {
            return null;
        }

        return [
            'container' => $container,
            'application' => $application,
            'server' => $application->service->server,
            'escapedContainer' => escapeshellarg($container['container_name']),
        ];
    }

    public function loadArtisanCommands(): void
    {
        $this->isLoadingCommands = true;
        $this->artisanCommands = [];
        $this->selectedCommand = null;
        $this->selectedCommandDescription = '';
        $this->selectedCommandHelp = '';

        try {
            $context = $this->getSelectedContainerContext();
            if (! $context) {
                $this->dispatch('error', 'Container not found or not running.');

                return;
            }

            $server = $context['server'];
            $escapedContainer = $context['escapedContainer'];

            // Prefer json output (easier to parse); fallback to plain output if unsupported.
            $command = "docker exec {$escapedContainer} php /var/www/html/artisan list --format=json";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }

            $raw = (string) (instant_remote_process([$command], $server, false) ?? '');
            $raw = trim($raw);

            $commands = $this->parseArtisanListOutput($raw);
            $this->artisanCommands = $commands;

            if ($this->artisanCommands !== []) {
                $this->selectedCommand = $this->artisanCommands[0]['name'];
                $this->selectedCommandDescription = $this->artisanCommands[0]['description'];
                $this->loadHelp();
            }
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error loading artisan commands: '.$e->getMessage());
        } finally {
            $this->isLoadingCommands = false;
        }
    }

    /**
     * @return array<int, array{name: string, description: string}>
     */
    private function parseArtisanListOutput(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $decoded = null;
        if (str_starts_with($raw, '{') || str_starts_with($raw, '[')) {
            $decoded = json_decode($raw, true);
        }

        if (is_array($decoded)) {
            $list = $decoded['commands'] ?? $decoded['data'] ?? $decoded;
            if (is_array($list)) {
                $result = [];
                foreach ($list as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $name = (string) ($item['name'] ?? $item['command'] ?? '');
                    $description = (string) ($item['description'] ?? $item['help'] ?? '');
                    if ($name !== '') {
                        $result[] = ['name' => $name, 'description' => $description];
                    }
                }

                return $result;
            }
        }

        // Plain output fallback: lines like "about  Display basic information about your application."
        $result = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || ! preg_match('/^([a-zA-Z0-9:\-]+)\s{2,}(.+)$/', $line, $m)) {
                continue;
            }

            $result[] = [
                'name' => (string) $m[1],
                'description' => trim((string) $m[2]),
            ];
        }

        return $result;
    }

    public function loadHelp(): void
    {
        if (! $this->selectedCommand) {
            $this->selectedCommandHelp = '';
            return;
        }

        $this->isLoadingHelp = true;
        $this->selectedCommandHelp = '';

        try {
            $context = $this->getSelectedContainerContext();
            if (! $context) {
                $this->dispatch('error', 'Container not found or not running.');

                return;
            }

            $server = $context['server'];
            $escapedContainer = $context['escapedContainer'];

            $helpCommand = "docker exec {$escapedContainer} php /var/www/html/artisan help ".escapeshellarg($this->selectedCommand);
            if ($server->isNonRoot()) {
                $helpCommand = "sudo {$helpCommand}";
            }

            $this->selectedCommandHelp = (string) (instant_remote_process([$helpCommand], $server, false) ?? '');
        } catch (\Throwable $e) {
            $this->selectedCommandHelp = 'Error loading help: '.$e->getMessage();
        } finally {
            $this->isLoadingHelp = false;
        }
    }

    public function selectCommand(string $command): void
    {
        $this->selectedCommand = $command;
        $selected = collect($this->artisanCommands)->firstWhere('name', $command);
        $this->selectedCommandDescription = (string) (data_get($selected, 'description', ''));
        $this->loadHelp();
    }

    public function updatedSelectedCommand(?string $value): void
    {
        if (! $value) {
            $this->selectedCommandDescription = '';
            $this->selectedCommandHelp = '';
            return;
        }

        $selected = collect($this->artisanCommands)->firstWhere('name', $value);
        $this->selectedCommandDescription = (string) (data_get($selected, 'description', ''));
        $this->loadHelp();
    }

    public function run(): void
    {
        $this->validate([
            'selectedContainer' => 'required|integer',
            'selectedCommand' => ['required', 'string', 'max:300'],
        ]);

        $context = $this->getSelectedContainerContext();
        if (! $context) {
            $this->dispatch('error', 'Container not found or not running.');

            return;
        }

        $server = $context['server'];
        $escapedContainer = $context['escapedContainer'];

        $tokens = preg_split('/\s+/', trim((string) $this->selectedCommand)) ?: [];
        $tokens = array_values(array_filter($tokens, fn ($t) => $t !== ''));

        if ($tokens === []) {
            $this->dispatch('error', 'Command is empty.');

            return;
        }

        // Disallow dangerous shell characters; docker exec will run the process directly,
        // but we still harden token inputs to avoid surprises.
        foreach ($tokens as $token) {
            if (preg_match('/[;&|`$<>\\\\]/', $token)) {
                $this->dispatch('error', 'Invalid characters in command.');

                return;
            }
        }

        $artisanArgs = implode(' ', array_map('escapeshellarg', $tokens));
        $command = "docker exec {$escapedContainer} php /var/www/html/artisan {$artisanArgs}";
        if ($server->isNonRoot()) {
            $command = "sudo {$command}";
        }

        $this->isRunning = true;
        $this->output = '';

        try {
            $this->output = (string) (instant_remote_process([$command], $server, false) ?? '');
            $this->dispatch('success', 'Artisan command executed.');
        } catch (\Throwable $e) {
            $this->output = $e->getMessage();
            $this->dispatch('error', 'Error running artisan: '.$e->getMessage());
        } finally {
            $this->isRunning = false;
        }
    }

    public function render()
    {
        return view('livewire.project.service.laravel-artisan');
    }
}

