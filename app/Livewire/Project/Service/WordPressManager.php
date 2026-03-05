<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class WordPressManager extends Component
{
    use AuthorizesRequests;

    public Service $service;

    public array $parameters;

    public $applications;

    public $wordpressContainers = [];

    public string $oldUrl = '';

    public string $newUrl = '';

    public bool $isProcessing = false;

    public string $output = '';

    public function mount()
    {
        try {
            $this->parameters = get_route_parameters();
            $this->service = Service::whereUuid(request()->route('service_uuid'))->firstOrFail();
            $this->authorize('view', $this->service);
            $this->applications = $this->service->applications->sort();
            $this->detectWordPressContainers();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function detectWordPressContainers()
    {
        $this->wordpressContainers = [];
        foreach ($this->applications as $application) {
            if ($this->isWordPressContainer($application)) {
                $containerName = $application->name.'-'.$this->service->uuid;
                $this->wordpressContainers[] = [
                    'id' => $application->id,
                    'name' => $application->name,
                    'container_name' => $containerName,
                    'status' => $application->status,
                    'application' => $application,
                ];
            }
        }
    }

    public function isWordPressContainer($application): bool
    {
        // Check if image contains wordpress
        if (str_contains(strtolower($application->image ?? ''), 'wordpress')) {
            return true;
        }

        // Check environment variables
        $envVars = $application->environment_variables()->get();
        foreach ($envVars as $envVar) {
            if (str_contains(strtoupper($envVar->key ?? ''), 'WORDPRESS')) {
                return true;
            }
        }

        // Check if wp-config.php exists (if container is running)
        if (str($application->status)->contains('running')) {
            try {
                $server = $application->service->server;
                $containerName = $application->name.'-'.$this->service->uuid;
                $escapedContainer = escapeshellarg($containerName);
                $command = "docker exec {$escapedContainer} sh -c 'test -f /var/www/html/wp-config.php && echo found || echo notfound'";
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

    public function syncUrls()
    {
        $this->validate([
            'oldUrl' => 'required|url',
            'newUrl' => 'required|url',
        ]);

        if (empty($this->wordpressContainers)) {
            $this->dispatch('error', 'No WordPress containers found.');

            return;
        }

        $this->isProcessing = true;
        $this->output = '';

        try {
            foreach ($this->wordpressContainers as $container) {
                $application = $container['application'] ?? $this->applications->find($container['id']);
                if (! $application || ! str($application->status)->contains('running')) {
                    $this->output .= "Skipping container {$container['name']} (not running)\n\n";
                    continue;
                }

                $server = $application->service->server;
                $containerName = $container['container_name'];

                // Check if WP-CLI is available
                $escapedContainer = escapeshellarg($containerName);
                $checkWpCli = "docker exec {$escapedContainer} sh -c 'cd /var/www/html && which wp || echo notfound'";
                if ($server->isNonRoot()) {
                    $checkWpCli = "sudo {$checkWpCli}";
                }
                $wpCliCheck = trim(instant_remote_process([$checkWpCli], $server, false) ?? '');
                
                if ($wpCliCheck === 'notfound' || empty($wpCliCheck)) {
                    $this->output .= "Container: {$container['name']}\n";
                    $this->output .= "Warning: WP-CLI not found. Skipping WP-CLI commands.\n\n";
                } else {
                    // Execute WP-CLI commands from WordPress directory
                    // Use --path flag to ensure WP-CLI runs in the correct directory
                    $wpPath = '/var/www/html';
                    $oldUrlEscaped = escapeshellarg($this->oldUrl);
                    $newUrlEscaped = escapeshellarg($this->newUrl);
                    
                    $commands = [
                        ['cmd' => "cd {$wpPath} && wp search-replace {$oldUrlEscaped} {$newUrlEscaped} --all-tables --allow-root", 'name' => 'Search & Replace URLs'],
                        ['cmd' => "cd {$wpPath} && wp elementor replace-url {$oldUrlEscaped} {$newUrlEscaped} --allow-root", 'name' => 'Elementor URL Replacement'],
                        ['cmd' => "cd {$wpPath} && wp elementor flush-css-cache --allow-root", 'name' => 'Flush Elementor CSS Cache'],
                        ['cmd' => "cd {$wpPath} && wp cache flush --allow-root", 'name' => 'Flush WordPress Cache'],
                    ];

                    foreach ($commands as $commandData) {
                        $command = $commandData['cmd'];
                        $commandName = $commandData['name'];
                        // Escape the entire command for sh -c
                        $dockerCommand = "docker exec {$escapedContainer} sh -c ".escapeshellarg($command);
                        if ($server->isNonRoot()) {
                            $dockerCommand = "sudo {$dockerCommand}";
                        }

                        try {
                            $output = instant_remote_process([$dockerCommand], $server, false);
                            $this->output .= "Container: {$container['name']}\n";
                            $this->output .= "Command: {$commandName}\n";
                            $this->output .= "Output: ".($output ?? 'Success')."\n\n";
                        } catch (\Throwable $e) {
                            $this->output .= "Container: {$container['name']}\n";
                            $this->output .= "Command: {$commandName}\n";
                            $this->output .= "Error: ".$e->getMessage()."\n\n";
                        }
                    }
                }

                // Fix permissions
                $this->fixPermissions($server, $containerName);
            }

            $this->dispatch('success', 'URLs synchronized successfully!');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error synchronizing URLs: '.$e->getMessage());
            $this->output .= "\nError: ".$e->getMessage();
        } finally {
            $this->isProcessing = false;
        }
    }

    public function fixPermissions($server, $containerName)
    {
        try {
            $escapedContainer = escapeshellarg($containerName);
            $commands = [
                "docker exec {$escapedContainer} sh -c 'chown -R www-data:www-data /var/www/html/wp-content'",
                "docker exec {$escapedContainer} sh -c 'find /var/www/html/wp-content -type d -exec chmod 755 {} \\;'",
                "docker exec {$escapedContainer} sh -c 'find /var/www/html/wp-content -type f -exec chmod 644 {} \\;'",
            ];

            foreach ($commands as $command) {
                if ($server->isNonRoot()) {
                    $command = "sudo {$command}";
                }
                instant_remote_process([$command], $server, false);
            }

            $this->output .= "Permissions fixed successfully.\n";
        } catch (\Throwable $e) {
            $this->output .= "Warning: Could not fix permissions: ".$e->getMessage()."\n";
        }
    }

    public function render()
    {
        return view('livewire.project.service.wordpress-manager');
    }
}
