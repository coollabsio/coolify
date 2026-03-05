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

    public array $wpPrefixes = [];

    public function mount()
    {
        try {
            $this->parameters = get_route_parameters();
            $this->service = Service::whereUuid(request()->route('service_uuid'))->firstOrFail();
            $this->authorize('view', $this->service);
            $this->applications = $this->service->applications->sort();
            $this->detectWordPressContainers();
            $this->detectWpPrefixes();
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

    public function detectWpPrefixes()
    {
        $this->wpPrefixes = [];
        
        foreach ($this->wordpressContainers as $container) {
            $application = $container['application'] ?? $this->applications->find($container['id']);
            if (! $application || ! str($application->status)->contains('running')) {
                continue;
            }

            try {
                $server = $application->service->server;
                $containerName = $container['container_name'];
                $prefix = $this->detectWpPrefix($server, $containerName);
                
                $this->wpPrefixes[$container['id']] = [
                    'container_name' => $container['name'],
                    'prefix' => $prefix ?? 'wp_',
                ];
            } catch (\Throwable $e) {
                $this->wpPrefixes[$container['id']] = [
                    'container_name' => $container['name'],
                    'prefix' => 'wp_',
                ];
            }
        }
    }

    public function detectWpPrefix($server, string $containerName): ?string
    {
        try {
            $escapedContainer = escapeshellarg($containerName);
            
            // Try to get prefix from wp-config.php
            $configCommand = "docker exec {$escapedContainer} sh -c 'cd /var/www/html && grep -E \"\\\$table_prefix\" wp-config.php 2>/dev/null | head -1 || echo notfound'";
            if ($server->isNonRoot()) {
                $configCommand = "sudo {$configCommand}";
            }
            $configOutput = trim(instant_remote_process([$configCommand], $server, false) ?? '');
            
            if ($configOutput !== 'notfound' && ! empty($configOutput)) {
                // Extract prefix from line like: $table_prefix = 'wp_';
                if (preg_match("/['\"]([^'\"]+)['\"]/", $configOutput, $matches)) {
                    return $matches[1];
                }
            }

            // Try to detect from database tables
            $prefix = $this->detectPrefixFromDatabase($server, $containerName);
            if ($prefix) {
                return $prefix;
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function detectPrefixFromDatabase($server, string $containerName): ?string
    {
        try {
            $escapedContainer = escapeshellarg($containerName);
            
            // Get environment variables to determine database type and credentials
            $envCommand = "docker exec {$escapedContainer} env";
            if ($server->isNonRoot()) {
                $envCommand = "sudo {$envCommand}";
            }
            $envOutput = instant_remote_process([$envCommand], $server, false) ?? '';
            $envVars = [];
            foreach (explode("\n", $envOutput) as $line) {
                if (str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    $envVars[$key] = $value;
                }
            }

            // Determine database type
            $isMariaDB = isset($envVars['MARIADB_ROOT_PASSWORD']) || isset($envVars['MARIADB_DATABASE']);
            $isMySQL = isset($envVars['MYSQL_ROOT_PASSWORD']) || isset($envVars['MYSQL_DATABASE']);

            if (! $isMariaDB && ! $isMySQL) {
                return null;
            }

            $rootPassword = $isMariaDB ? ($envVars['MARIADB_ROOT_PASSWORD'] ?? '') : ($envVars['MYSQL_ROOT_PASSWORD'] ?? '');
            $database = $isMariaDB ? ($envVars['MARIADB_DATABASE'] ?? '') : ($envVars['MYSQL_DATABASE'] ?? '');

            if (empty($rootPassword) || empty($database)) {
                return null;
            }

            $dbCommand = $isMariaDB ? 'mariadb' : 'mysql';
            $passwordVar = $isMariaDB ? 'MARIADB_ROOT_PASSWORD' : 'MYSQL_ROOT_PASSWORD';
            $escapedPassword = str_replace("'", "'\\''", $rootPassword);
            $escapedDatabase = escapeshellarg($database);

            // Get list of tables and find WordPress prefix
            $tablesCommand = "docker exec {$escapedContainer} sh -c 'export {$passwordVar}=\"{$escapedPassword}\" && {$dbCommand} -u root --password=\${$passwordVar} {$escapedDatabase} -e \"SHOW TABLES;\" 2>&1'";
            if ($server->isNonRoot()) {
                $tablesCommand = "sudo {$tablesCommand}";
            }
            
            $tablesOutput = instant_remote_process([$tablesCommand], $server, false);
            if (empty($tablesOutput)) {
                return null;
            }

            // Look for WordPress core tables (posts, users, options, etc.)
            $wpCoreTables = ['posts', 'users', 'options', 'comments', 'terms', 'postmeta'];
            $lines = explode("\n", trim($tablesOutput));
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || stripos($line, 'tables_in_') === 0) {
                    continue;
                }
                
                // Check if this table matches WordPress pattern
                foreach ($wpCoreTables as $coreTable) {
                    if (str_ends_with(strtolower($line), $coreTable)) {
                        // Extract prefix (everything before the core table name)
                        $prefix = substr($line, 0, -strlen($coreTable));
                        if (! empty($prefix)) {
                            return $prefix;
                        }
                    }
                }
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function updateWpPrefix(int $containerId, string $newPrefix)
    {
        // Validate prefix format
        if (empty($newPrefix) || strlen($newPrefix) > 20 || ! preg_match('/^[a-z0-9_]+$/i', $newPrefix)) {
            $this->dispatch('error', 'El prefijo solo puede contener letras, números y guiones bajos (máximo 20 caracteres).');
            return;
        }

        $container = collect($this->wordpressContainers)->firstWhere('id', $containerId);
        if (! $container) {
            $this->dispatch('error', 'Container not found.');
            return;
        }

        $application = $container['application'] ?? $this->applications->find($containerId);
        if (! $application || ! str($application->status)->contains('running')) {
            $this->dispatch('error', 'Container is not running.');
            return;
        }

        try {
            $server = $application->service->server;
            $containerName = $container['container_name'];
            $escapedContainer = escapeshellarg($containerName);
            $escapedPrefix = escapeshellarg($newPrefix);

            // Update wp-config.php
            $updateCommand = "docker exec {$escapedContainer} sh -c 'cd /var/www/html && sed -i \"s/\\\$table_prefix.*=.*['\\\"][^'\\\"]*['\\\"]/\\\$table_prefix = {$escapedPrefix}/\" wp-config.php 2>&1 || true'";
            if ($server->isNonRoot()) {
                $updateCommand = "sudo {$updateCommand}";
            }

            $output = instant_remote_process([$updateCommand], $server, false);
            
            // Refresh prefixes
            $this->detectWpPrefixes();
            
            $this->dispatch('success', "WordPress prefix updated to {$newPrefix}.");
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to update WordPress prefix: '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.project.service.wordpress-manager');
    }
}
