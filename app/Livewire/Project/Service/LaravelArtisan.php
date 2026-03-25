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

    public string $command = '';

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

    public function run(): void
    {
        $this->validate([
            'selectedContainer' => 'required|integer',
            'command' => ['required', 'string', 'max:2000'],
        ]);

        $container = collect($this->laravelContainers)->firstWhere('id', $this->selectedContainer);
        if (! $container) {
            $this->dispatch('error', 'Container not found.');

            return;
        }

        $application = $container['application'] ?? $this->applications->find($container['id']);
        if (! $application || ! str($application->status)->contains('running')) {
            $this->dispatch('error', 'Container is not running.');

            return;
        }

        $tokens = preg_split('/\s+/', trim($this->command)) ?: [];
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

        $server = $application->service->server;
        $escapedContainer = escapeshellarg($container['container_name']);

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

