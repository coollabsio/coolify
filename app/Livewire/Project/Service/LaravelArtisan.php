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

    /** @var array<int, array{name: string, description: string}> */
    public array $filteredArtisanCommands = [];

    public bool $isLoadingHelp = false;

    public string $selectedCommandHelp = '';

    public string $output = '';

    public bool $isRunning = false;

    // Prevent the dropdown from reopening right after selecting an item.
    public bool $suppressCommandDropdown = false;

    /**
     * Returns the artisan sub-command token (the token after `artisan` if present).
     */
    private function getArtisanSubcommandToken(string $value): string
    {
        $tokens = preg_split('/\s+/', trim($value)) ?: [];
        $tokens = array_values(array_filter($tokens, fn ($t) => $t !== ''));
        if ($tokens === []) {
            return '';
        }

        foreach ($tokens as $index => $token) {
            if (strtolower((string) $token) !== 'artisan') {
                continue;
            }

            return (string) ($tokens[$index + 1] ?? '');
        }

        return (string) ($tokens[0] ?? '');
    }

    public function showPopularCommands(): void
    {
        if ($this->isLoadingCommands) {
            return;
        }

        if (trim((string) $this->selectedCommand) !== '') {
            $this->refreshCommandDropdown();

            return;
        }

        if ($this->artisanCommands === []) {
            return;
        }

        $popular = [
            'env',
            'about',
            'migrate',
            'db:seed',
            'optimize',
            'optimize:clear',
            'config:cache',
            'config:clear',
            'cache:clear',
            'route:list',
            'queue:work',
            'schedule:run',
            'view:clear',
            'storage:link',
        ];

        $byName = collect($this->artisanCommands)->keyBy('name');
        $result = [];

        foreach ($popular as $name) {
            $cmd = $byName->get($name);
            if (is_array($cmd)) {
                $result[] = $cmd;
            }
        }

        if (count($result) < 10) {
            foreach ($this->artisanCommands as $cmd) {
                if (count($result) >= 10) {
                    break;
                }
                if (in_array($cmd['name'] ?? '', $popular, true)) {
                    continue;
                }
                $result[] = $cmd;
            }
        }

        $this->filteredArtisanCommands = array_values(array_slice($result, 0, 10));
    }

    public function mount(): void
    {
        $this->parameters = get_route_parameters();
        $this->service = Service::whereUuid(request()->route('service_uuid'))->firstOrFail();
        $this->authorize('view', $this->service);
        $this->applications = $this->service->applications->sort();
        $this->detectLaravelContainers();

        // Auto-pick the first running Laravel container; UI no longer allows manual selection.
        if ($this->selectedContainer === null && ! empty($this->laravelContainers)) {
            $this->selectedContainer = (int) ($this->laravelContainers[0]['id'] ?? null);
        }

        if ($this->selectedContainer) {
            $this->loadArtisanCommands();
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
        $this->selectedCommand = '';
        $this->selectedCommandDescription = '';
        $this->selectedCommandHelp = '';
        $this->filteredArtisanCommands = [];

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
                // Leave the input empty; dropdown will populate when the user types.
            }
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error loading artisan commands: '.$e->getMessage());
        } finally {
            $this->isLoadingCommands = false;
        }
    }

    private function refreshCommandDropdown(): void
    {
        $value = trim((string) $this->selectedCommand);
        if ($value === '') {
            $this->filteredArtisanCommands = [];

            return;
        }

        $subcommand = $this->getArtisanSubcommandToken($value);
        if ($subcommand === '') {
            $this->filteredArtisanCommands = [];

            return;
        }

        $q = strtolower($subcommand);
        $startsWith = array_values(array_filter(
            $this->artisanCommands,
            fn (array $cmd) => str_starts_with(strtolower((string) ($cmd['name'] ?? '')), $q)
        ));

        $containsElsewhere = array_values(array_filter(
            $this->artisanCommands,
            fn (array $cmd) => ! str_starts_with(strtolower((string) ($cmd['name'] ?? '')), $q)
                && str_contains(strtolower((string) ($cmd['name'] ?? '')), $q)
        ));

        // Order: exact prefix matches first, then "contains" matches.
        $merged = array_merge($startsWith, $containsElsewhere);
        $this->filteredArtisanCommands = array_values(array_slice($merged, 0, 10));
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
        $this->suppressCommandDropdown = true;
        $this->selectedCommand = $command;
        $name = trim((string) ($command ? preg_split('/\s+/', $command)[0] : ''));
        $selected = collect($this->artisanCommands)->firstWhere('name', $name);
        $this->selectedCommandDescription = (string) (data_get($selected, 'description', ''));
        $this->filteredArtisanCommands = [];
    }

    public function updatedSelectedCommand(?string $value): void
    {
        if ($this->suppressCommandDropdown) {
            $this->suppressCommandDropdown = false;
            $this->filteredArtisanCommands = [];

            return;
        }

        if (! $value) {
            $this->selectedCommandDescription = '';
            $this->selectedCommandHelp = '';
            $this->filteredArtisanCommands = [];
            return;
        }

        $subcommand = $this->getArtisanSubcommandToken((string) $value);
        $selected = collect($this->artisanCommands)->firstWhere('name', $subcommand);
        $this->selectedCommandDescription = (string) (data_get($selected, 'description', ''));
        $this->selectedCommandHelp = '';

        $this->refreshCommandDropdown();
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

        $rawTokens = preg_split('/\s+/', trim((string) $this->selectedCommand)) ?: [];
        $rawTokens = array_values(array_filter($rawTokens, fn ($t) => $t !== ''));
        if ($rawTokens === []) {
            $this->dispatch('error', 'Command is empty.');

            return;
        }

        // Allow users to type either:
        // - "migrate --force"
        // - "php artisan migrate --force"
        // Strip leading "php artisan" when present.
        $artisanIndex = null;
        foreach ($rawTokens as $index => $token) {
            if (strtolower((string) $token) === 'artisan') {
                $artisanIndex = $index;
                break;
            }
        }

        $tokens = $artisanIndex !== null ? array_slice($rawTokens, $artisanIndex + 1) : $rawTokens;
        $tokens = array_values(array_filter($tokens, fn ($t) => $t !== ''));

        if ($tokens === []) {
            $this->dispatch('error', 'Command is empty (no artisan sub-command found).');

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

