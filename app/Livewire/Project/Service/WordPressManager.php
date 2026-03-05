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

    public ?int $selectedContainerForPhpIni = null;

    public array $phpIniSettings = [];

    public bool $isLoadingPhpIni = false;

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

    public function loadPhpIniSettings(?int $containerId = null)
    {
        if ($containerId === null) {
            $containerId = $this->selectedContainerForPhpIni;
        }

        if ($containerId === null) {
            return;
        }

        $this->isLoadingPhpIni = true;
        $this->selectedContainerForPhpIni = $containerId;

        try {
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

            $server = $application->service->server;
            $containerName = $container['container_name'];
            $escapedContainer = escapeshellarg($containerName);

            // Get PHP configuration using php -i
            $phpInfoCommand = "docker exec {$escapedContainer} php -i 2>/dev/null";
            if ($server->isNonRoot()) {
                $phpInfoCommand = "sudo {$phpInfoCommand}";
            }

            $phpInfo = instant_remote_process([$phpInfoCommand], $server, false) ?? '';

            // Extract common PHP settings
            $settings = [];
            $settingsToExtract = [
                'upload_max_filesize',
                'post_max_size',
                'max_execution_time',
                'max_input_time',
                'memory_limit',
                'max_input_vars',
                'max_file_uploads',
            ];

            foreach ($settingsToExtract as $setting) {
                // Try using php -r to get specific ini value (more reliable)
                $getIniCommand = "docker exec {$escapedContainer} php -r \"echo ini_get('{$setting}');\" 2>/dev/null";
                if ($server->isNonRoot()) {
                    $getIniCommand = "sudo {$getIniCommand}";
                }
                $iniValue = trim(instant_remote_process([$getIniCommand], $server, false) ?? '');
                
                if (! empty($iniValue)) {
                    $settings[$setting] = $iniValue;
                } else {
                    // Fallback to php -i parsing
                    $pattern = "/{$setting}\s*=>\s*([^\r\n]+)/i";
                    if (preg_match($pattern, $phpInfo, $matches)) {
                        $value = trim($matches[1]);
                        // Extract only the value part (remove "=> value" if present)
                        $value = preg_replace('/.*?=>\s*/', '', $value);
                        // Remove any trailing spaces or special characters
                        $value = preg_replace('/\s*\(.*?\)\s*$/', '', $value);
                        $settings[$setting] = trim($value);
                    } else {
                        $settings[$setting] = 'N/A';
                    }
                }
            }

            $this->phpIniSettings = $settings;
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error loading PHP configuration: '.$e->getMessage());
            $this->phpIniSettings = [];
        } finally {
            $this->isLoadingPhpIni = false;
        }
    }

    public function updatePhpIniSetting(string $setting, string $value)
    {
        if ($this->selectedContainerForPhpIni === null) {
            $this->dispatch('error', 'No container selected.');
            return;
        }

        // Validate setting name
        $allowedSettings = [
            'upload_max_filesize',
            'post_max_size',
            'max_execution_time',
            'max_input_time',
            'memory_limit',
            'max_input_vars',
            'max_file_uploads',
        ];

        if (! in_array($setting, $allowedSettings)) {
            $this->dispatch('error', 'Invalid setting name.');
            return;
        }

        // Validate value format
        if (empty($value)) {
            $this->dispatch('error', 'Value cannot be empty.');
            return;
        }

        try {
            $container = collect($this->wordpressContainers)->firstWhere('id', $this->selectedContainerForPhpIni);
            if (! $container) {
                $this->dispatch('error', 'Container not found.');
                return;
            }

            $application = $container['application'] ?? $this->applications->find($this->selectedContainerForPhpIni);
            if (! $application || ! str($application->status)->contains('running')) {
                $this->dispatch('error', 'Container is not running.');
                return;
            }

            $server = $application->service->server;
            $containerName = $container['container_name'];
            $escapedContainer = escapeshellarg($containerName);
            $escapedSetting = escapeshellarg($setting);
            $escapedValue = escapeshellarg($value);

            // Find php.ini location - multiple methods
            $phpIniPath = null;

            // Method 1: Get from php -i
            $findPhpIniCommand = "docker exec {$escapedContainer} php -i 2>/dev/null | grep -E 'Loaded Configuration File|php.ini' | head -1 | awk -F'=> ' '{print \$2}' | awk '{print \$1}'";
            if ($server->isNonRoot()) {
                $findPhpIniCommand = "sudo {$findPhpIniCommand}";
            }
            $phpIniPath = trim(instant_remote_process([$findPhpIniCommand], $server, false) ?? '');

            // Method 2: Try php -r to get ini file path
            if (empty($phpIniPath) || $phpIniPath === '(none)' || $phpIniPath === 'no value') {
                $getIniPathCommand = "docker exec {$escapedContainer} php -r \"echo php_ini_loaded_file() ?: php_ini_scanned_files();\" 2>/dev/null";
                if ($server->isNonRoot()) {
                    $getIniPathCommand = "sudo {$getIniPathCommand}";
                }
                $phpIniPath = trim(instant_remote_process([$getIniPathCommand], $server, false) ?? '');
                // If scanned files, get first one
                if (!empty($phpIniPath) && str_contains($phpIniPath, ',')) {
                    $phpIniPath = trim(explode(',', $phpIniPath)[0]);
                }
            }

            // Method 3: Search common locations
            if (empty($phpIniPath) || $phpIniPath === '(none)' || $phpIniPath === 'no value' || !str_starts_with($phpIniPath, '/')) {
                $commonPaths = [
                    '/usr/local/etc/php/php.ini',
                    '/usr/local/etc/php/php.ini-development',
                    '/usr/local/etc/php/php.ini-production',
                    '/etc/php/8.3/fpm/php.ini',
                    '/etc/php/8.3/cli/php.ini',
                    '/etc/php/8.2/fpm/php.ini',
                    '/etc/php/8.2/cli/php.ini',
                    '/etc/php/8.1/fpm/php.ini',
                    '/etc/php/8.1/cli/php.ini',
                    '/etc/php/8.0/fpm/php.ini',
                    '/etc/php/8.0/cli/php.ini',
                    '/etc/php/7.4/fpm/php.ini',
                    '/etc/php/7.4/cli/php.ini',
                    '/etc/php7/php.ini',
                    '/etc/php/php.ini',
                    '/etc/php.ini',
                    '/opt/bitnami/php/etc/php.ini',
                    '/usr/local/lib/php.ini',
                ];

                foreach ($commonPaths as $path) {
                    $testCommand = "docker exec {$escapedContainer} test -f ".escapeshellarg($path)." && echo found || echo notfound";
                    if ($server->isNonRoot()) {
                        $testCommand = "sudo {$testCommand}";
                    }
                    $testResult = trim(instant_remote_process([$testCommand], $server, false) ?? '');
                    if ($testResult === 'found') {
                        $phpIniPath = $path;
                        break;
                    }
                }
            }

            // Method 4: Find any php.ini file in common directories
            if (empty($phpIniPath) || $phpIniPath === '(none)' || $phpIniPath === 'no value' || !str_starts_with($phpIniPath, '/')) {
                $searchDirs = [
                    '/usr/local/etc/php',
                    '/etc/php',
                    '/etc',
                ];

                foreach ($searchDirs as $dir) {
                    $findCommand = "docker exec {$escapedContainer} find {$dir} -name 'php.ini' -type f 2>/dev/null | head -1";
                    if ($server->isNonRoot()) {
                        $findCommand = "sudo {$findCommand}";
                    }
                    $foundPath = trim(instant_remote_process([$findCommand], $server, false) ?? '');
                    if (!empty($foundPath) && str_starts_with($foundPath, '/')) {
                        $phpIniPath = $foundPath;
                        break;
                    }
                }
            }

            // Method 5: If still not found, use the default location and create it if needed
            if (empty($phpIniPath) || $phpIniPath === '(none)' || $phpIniPath === 'no value' || !str_starts_with($phpIniPath, '/')) {
                // Try to determine PHP version and use appropriate path
                $phpVersionCommand = "docker exec {$escapedContainer} php -r \"echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;\" 2>/dev/null";
                if ($server->isNonRoot()) {
                    $phpVersionCommand = "sudo {$phpVersionCommand}";
                }
                $phpVersion = trim(instant_remote_process([$phpVersionCommand], $server, false) ?? '');
                
                if (!empty($phpVersion)) {
                    $phpIniPath = "/usr/local/etc/php/php.ini";
                } else {
                    $phpIniPath = "/usr/local/etc/php/php.ini";
                }

                // Check if directory exists, create if not
                $dirPath = dirname($phpIniPath);
                $checkDirCommand = "docker exec {$escapedContainer} test -d ".escapeshellarg($dirPath)." || docker exec {$escapedContainer} mkdir -p ".escapeshellarg($dirPath);
                if ($server->isNonRoot()) {
                    $checkDirCommand = "sudo {$checkDirCommand}";
                }
                instant_remote_process([$checkDirCommand], $server, false);

                // Create php.ini if it doesn't exist
                $checkFileCommand = "docker exec {$escapedContainer} test -f ".escapeshellarg($phpIniPath)." || docker exec {$escapedContainer} sh -c 'echo \"; PHP Configuration File\" > ".escapeshellarg($phpIniPath)."'";
                if ($server->isNonRoot()) {
                    $checkFileCommand = "sudo {$checkFileCommand}";
                }
                instant_remote_process([$checkFileCommand], $server, false);
            }

            // Update the setting in php.ini - read, modify, write approach
            $escapedPhpIniPath = escapeshellarg($phpIniPath);
            
            // Read current php.ini content
            $readCommand = "docker exec {$escapedContainer} cat {$escapedPhpIniPath}";
            if ($server->isNonRoot()) {
                $readCommand = "sudo {$readCommand}";
            }
            $phpIniContent = instant_remote_process([$readCommand], $server, false) ?? '';
            
            // Update the setting in content
            $lines = explode("\n", $phpIniContent);
            $found = false;
            $newLines = [];
            
            foreach ($lines as $line) {
                $trimmedLine = trim($line);
                // Check if this line contains our setting (commented or not)
                if (preg_match('/^[;]*\s*'.preg_quote($setting, '/').'\s*=/i', $trimmedLine)) {
                    // Replace the line
                    $newLines[] = "{$setting} = {$value}";
                    $found = true;
                } else {
                    $newLines[] = $line;
                }
            }
            
            // If setting not found, add it at the end
            if (! $found) {
                $newLines[] = "";
                $newLines[] = "; {$setting} - Updated by Coolify";
                $newLines[] = "{$setting} = {$value}";
            }
            
            // Write back to container
            $newContent = implode("\n", $newLines);
            $escapedContent = escapeshellarg($newContent);
            $writeCommand = "docker exec {$escapedContainer} sh -c 'echo {$escapedContent} > {$escapedPhpIniPath}'";
            if ($server->isNonRoot()) {
                $writeCommand = "sudo {$writeCommand}";
            }
            instant_remote_process([$writeCommand], $server, false);

            // Also check and update conf.d directory if it exists (for PHP-FPM)
            $confDirs = [
                dirname($phpIniPath).'/conf.d',
                '/usr/local/etc/php/conf.d',
                '/etc/php/'.(explode('/', $phpIniPath)[3] ?? '8.2').'/fpm/conf.d',
                '/etc/php/'.(explode('/', $phpIniPath)[3] ?? '8.2').'/cli/conf.d',
            ];
            
            foreach ($confDirs as $confDir) {
                $checkDirCommand = "docker exec {$escapedContainer} test -d ".escapeshellarg($confDir)." && echo found || echo notfound";
                if ($server->isNonRoot()) {
                    $checkDirCommand = "sudo {$checkDirCommand}";
                }
                $dirExists = trim(instant_remote_process([$checkDirCommand], $server, false) ?? '');
                
                if ($dirExists === 'found') {
                    // Create or update a custom ini file in conf.d
                    $customIniFile = $confDir.'/99-custom-'.$setting.'.ini';
                    $escapedCustomIni = escapeshellarg($customIniFile);
                    $customContent = "; Custom {$setting} setting\n{$setting} = {$value}\n";
                    $escapedCustomContent = escapeshellarg($customContent);
                    $writeCustomCommand = "docker exec {$escapedContainer} sh -c 'echo {$escapedCustomContent} > {$escapedCustomIni}'";
                    if ($server->isNonRoot()) {
                        $writeCustomCommand = "sudo {$writeCustomCommand}";
                    }
                    instant_remote_process([$writeCustomCommand], $server, false);
                    break; // Only update one conf.d directory
                }
            }

            // Reload PHP-FPM more aggressively
            $reloadCommands = [
                // Try to reload PHP-FPM
                "docker exec {$escapedContainer} sh -c 'pkill -USR2 php-fpm 2>/dev/null || true'",
                // Try to reload via service
                "docker exec {$escapedContainer} sh -c 'service php-fpm reload 2>/dev/null || service php8.2-fpm reload 2>/dev/null || service php8.1-fpm reload 2>/dev/null || service php8.0-fpm reload 2>/dev/null || true'",
                // Try to restart PHP-FPM
                "docker exec {$escapedContainer} sh -c 'service php-fpm restart 2>/dev/null || service php8.2-fpm restart 2>/dev/null || service php8.1-fpm restart 2>/dev/null || service php8.0-fpm restart 2>/dev/null || true'",
            ];
            
            foreach ($reloadCommands as $reloadCommand) {
                if ($server->isNonRoot()) {
                    $reloadCommand = "sudo {$reloadCommand}";
                }
                instant_remote_process([$reloadCommand], $server, false);
            }
            
            // Verify the change was applied
            $verifyCommand = "docker exec {$escapedContainer} php -r \"echo ini_get('{$setting}');\" 2>/dev/null";
            if ($server->isNonRoot()) {
                $verifyCommand = "sudo {$verifyCommand}";
            }
            $verifiedValue = trim(instant_remote_process([$verifyCommand], $server, false) ?? '');
            
            if (!empty($verifiedValue) && $verifiedValue !== $value) {
                $this->dispatch('warning', "Setting updated in php.ini but may require container restart. Current value: {$verifiedValue}, Expected: {$value}");
            }

            // Reload settings
            $this->loadPhpIniSettings($this->selectedContainerForPhpIni);

            if (!empty($verifiedValue) && $verifiedValue === $value) {
                $this->dispatch('success', "PHP setting {$setting} updated to {$value} and applied successfully.");
            } else {
                $this->dispatch('success', "PHP setting {$setting} updated to {$value} in php.ini. Please restart the container for changes to take full effect.");
            }
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error updating PHP setting: '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.project.service.wordpress-manager');
    }
}
