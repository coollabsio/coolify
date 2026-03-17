<?php

namespace App\Livewire\Project\Service;

use App\Models\LocalFileVolume;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
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

            // Create a temporary PHP script to update wp-config.php
            // This method is more reliable than sed/perl for PHP files
            $scriptContent = <<<'PHP'
<?php
$file = '/var/www/html/wp-config.php';
if (!file_exists($file)) {
    echo "ERROR: wp-config.php not found\n";
    exit(1);
}

$content = file_get_contents($file);
$newPrefix = $argv[1] ?? '';

if (empty($newPrefix)) {
    echo "ERROR: No prefix provided\n";
    exit(1);
}

// Replace table_prefix with new value, handling both single and double quotes
$pattern = '/(\$table_prefix\s*=\s*)["\']([^"\']*)["\']/';
$replacement = '$1"' . $newPrefix . '"';
$content = preg_replace($pattern, $replacement, $content);

if (file_put_contents($file, $content) === false) {
    echo "ERROR: Failed to write wp-config.php\n";
    exit(1);
}

echo "SUCCESS\n";
PHP;

            // Write script to container, execute it, then remove it
            $scriptPath = '/tmp/update_prefix_' . uniqid() . '.php';
            $escapedScriptPath = escapeshellarg($scriptPath);
            $escapedScriptContent = escapeshellarg($scriptContent);

            // Write script
            $writeCommand = "docker exec {$escapedContainer} sh -c 'echo {$escapedScriptContent} > {$escapedScriptPath}'";
            if ($server->isNonRoot()) {
                $writeCommand = "sudo {$writeCommand}";
            }
            instant_remote_process([$writeCommand], $server, false);

            // Execute script
            $executeCommand = "docker exec {$escapedContainer} sh -c 'cd /var/www/html && php {$escapedScriptPath} {$escapedPrefix} 2>&1'";
            if ($server->isNonRoot()) {
                $executeCommand = "sudo {$executeCommand}";
            }
            $output = instant_remote_process([$executeCommand], $server, false);

            // Remove script
            $removeCommand = "docker exec {$escapedContainer} sh -c 'rm -f {$escapedScriptPath}'";
            if ($server->isNonRoot()) {
                $removeCommand = "sudo {$removeCommand}";
            }
            instant_remote_process([$removeCommand], $server, false);

            // Verify the change was made correctly
            $finalVerify = $this->detectWpPrefix($server, $containerName);
            if ($finalVerify !== $newPrefix) {
                $this->dispatch('error', "No se pudo actualizar el prefijo. El prefijo actual es: ".($finalVerify ?? 'no detectado').". Output: ".($output ?? 'sin salida'));
                return;
            }

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

            // Get PHP configuration - try PHP-FPM first, then CLI
            $phpInfo = '';
            $fpmInfoCommand = "docker exec {$escapedContainer} sh -c 'which php-fpm >/dev/null 2>&1 && php-fpm -i 2>/dev/null || echo notfound'";
            if ($server->isNonRoot()) {
                $fpmInfoCommand = "sudo {$fpmInfoCommand}";
            }
            $fpmInfo = instant_remote_process([$fpmInfoCommand], $server, false) ?? '';

            if (!empty($fpmInfo) && $fpmInfo !== 'notfound') {
                $phpInfo = $fpmInfo;
            } else {
                // Fallback to CLI
                $phpInfoCommand = "docker exec {$escapedContainer} php -i 2>/dev/null";
                if ($server->isNonRoot()) {
                    $phpInfoCommand = "sudo {$phpInfoCommand}";
                }
                $phpInfo = instant_remote_process([$phpInfoCommand], $server, false) ?? '';
            }

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
                // Try multiple methods to get the value
                $iniValue = null;

                // Method 1: Check if there's a LocalFileVolume for this setting (persistent volume)
                $confIniFileName = '99-custom-'.$setting.'.ini';
                $confIniFileMountPath = '/usr/local/etc/php/conf.d/'.$confIniFileName;
                $fileVolume = LocalFileVolume::where('resource_type', ServiceApplication::class)
                    ->where('resource_id', $application->id)
                    ->where('mount_path', $confIniFileMountPath)
                    ->first();

                if ($fileVolume && !empty($fileVolume->content)) {
                    // Try to extract value from volume content first
                    if (preg_match('/'.$setting.'\s*=\s*([^\s\r\n]+)/', $fileVolume->content, $matches)) {
                        $iniValue = trim($matches[1]);
                    }
                }

                // Method 2: Try PHP-FPM directly if available
                if (empty($iniValue)) {
                    $fpmCommand = "docker exec {$escapedContainer} sh -c 'which php-fpm && php-fpm -i 2>/dev/null | grep \"{$setting}\" | head -1 | awk -F\"=> \" \"{print \\\$2}\" | awk \"{print \\\$1}\" || echo notfound'";
                    if ($server->isNonRoot()) {
                        $fpmCommand = "sudo {$fpmCommand}";
                    }
                    $fpmValue = trim(instant_remote_process([$fpmCommand], $server, false) ?? '');
                    if (!empty($fpmValue) && $fpmValue !== 'notfound') {
                        $iniValue = $fpmValue;
                    }
                }

                // Method 3: Try php -r (CLI, but more reliable)
                if (empty($iniValue)) {
                    $getIniCommand = "docker exec {$escapedContainer} php -r \"echo ini_get('{$setting}');\" 2>/dev/null";
                    if ($server->isNonRoot()) {
                        $getIniCommand = "sudo {$getIniCommand}";
                    }
                    $iniValue = trim(instant_remote_process([$getIniCommand], $server, false) ?? '');
                }

                // Method 4: Parse from php -i output
                if (empty($iniValue)) {
                    $pattern = "/{$setting}\s*=>\s*([^\r\n]+)/i";
                    if (preg_match($pattern, $phpInfo, $matches)) {
                        $value = trim($matches[1]);
                        $value = preg_replace('/.*?=>\s*/', '', $value);
                        $value = preg_replace('/\s*\(.*?\)\s*$/', '', $value);
                        $iniValue = trim($value);
                    }
                }

                $settings[$setting] = ! empty($iniValue) ? $iniValue : 'N/A';
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

            // Detect PHP SAPI (CLI vs FPM)
            $sapiCommand = "docker exec {$escapedContainer} php -r \"echo php_sapi_name();\" 2>/dev/null";
            if ($server->isNonRoot()) {
                $sapiCommand = "sudo {$sapiCommand}";
            }
            $sapi = strtolower(trim(instant_remote_process([$sapiCommand], $server, false) ?? 'cli'));

            // Get PHP version
            $phpVersionCommand = "docker exec {$escapedContainer} php -r \"echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;\" 2>/dev/null";
            if ($server->isNonRoot()) {
                $phpVersionCommand = "sudo {$phpVersionCommand}";
            }
            $phpVersion = trim(instant_remote_process([$phpVersionCommand], $server, false) ?? '8.2');

            // Find php.ini location - prioritize FPM if available
            $phpIniPaths = [];

            // Priority order: FPM conf.d > FPM php.ini > CLI conf.d > CLI php.ini > common locations
            $priorityPaths = [
                // PHP-FPM conf.d (highest priority - these override php.ini)
                "/usr/local/etc/php/conf.d/99-custom-{$setting}.ini",
                "/etc/php/{$phpVersion}/fpm/conf.d/99-custom-{$setting}.ini",
                "/etc/php/{$phpVersion}/fpm/conf.d/zzz-custom-{$setting}.ini",
                // PHP-FPM php.ini
                "/usr/local/etc/php/php.ini",
                "/etc/php/{$phpVersion}/fpm/php.ini",
                // CLI conf.d
                "/etc/php/{$phpVersion}/cli/conf.d/99-custom-{$setting}.ini",
                // CLI php.ini
                "/etc/php/{$phpVersion}/cli/php.ini",
                // Common locations
                "/etc/php/php.ini",
                "/etc/php.ini",
            ];

            // First, try to get from php -i (what PHP actually uses)
            $findPhpIniCommand = "docker exec {$escapedContainer} php -i 2>/dev/null | grep 'Loaded Configuration File' | awk -F'=> ' '{print \$2}' | awk '{print \$1}'";
            if ($server->isNonRoot()) {
                $findPhpIniCommand = "sudo {$findPhpIniCommand}";
            }
            $detectedIni = trim(instant_remote_process([$findPhpIniCommand], $server, false) ?? '');

            if (!empty($detectedIni) && $detectedIni !== '(none)' && str_starts_with($detectedIni, '/')) {
                $priorityPaths = array_merge([$detectedIni], $priorityPaths);
            }

            // Find existing conf.d directories (these have priority over php.ini)
            $confDirs = [
                "/usr/local/etc/php/conf.d",
                "/etc/php/{$phpVersion}/fpm/conf.d",
                "/etc/php/{$phpVersion}/cli/conf.d",
                "/etc/php/conf.d",
            ];

            $phpIniPath = null;
            $confDirPath = null;

            // First, check if conf.d exists and use it (highest priority)
            foreach ($confDirs as $confDir) {
                $checkDirCommand = "docker exec {$escapedContainer} test -d ".escapeshellarg($confDir)." && echo found || echo notfound";
                if ($server->isNonRoot()) {
                    $checkDirCommand = "sudo {$checkDirCommand}";
                }
                $dirExists = trim(instant_remote_process([$checkDirCommand], $server, false) ?? '');

                if ($dirExists === 'found') {
                    $confDirPath = $confDir;
                    break;
                }
            }

            // Then find php.ini file
            foreach ($priorityPaths as $path) {
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

            // If no php.ini found, use default and create it
            if ($phpIniPath === null) {
                $phpIniPath = "/usr/local/etc/php/php.ini";
                $dirPath = dirname($phpIniPath);
                $checkDirCommand = "docker exec {$escapedContainer} test -d ".escapeshellarg($dirPath)." || docker exec {$escapedContainer} mkdir -p ".escapeshellarg($dirPath);
                if ($server->isNonRoot()) {
                    $checkDirCommand = "sudo {$checkDirCommand}";
                }
                instant_remote_process([$checkDirCommand], $server, false);

                $checkFileCommand = "docker exec {$escapedContainer} test -f ".escapeshellarg($phpIniPath)." || docker exec {$escapedContainer} sh -c 'echo \"; PHP Configuration File\" > ".escapeshellarg($phpIniPath)."'";
                if ($server->isNonRoot()) {
                    $checkFileCommand = "sudo {$checkFileCommand}";
                }
                instant_remote_process([$checkFileCommand], $server, false);
            }

            // Ensure conf.d exists if we're going to use it
            if ($confDirPath === null) {
                $confDirPath = "/usr/local/etc/php/conf.d";
                $checkDirCommand = "docker exec {$escapedContainer} test -d ".escapeshellarg($confDirPath)." || docker exec {$escapedContainer} mkdir -p ".escapeshellarg($confDirPath);
                if ($server->isNonRoot()) {
                    $checkDirCommand = "sudo {$checkDirCommand}";
                }
                instant_remote_process([$checkDirCommand], $server, false);
            }

            // PRIORITY: conf.d files override php.ini, so we ALWAYS update conf.d first
            // This ensures the setting takes effect even if php.ini has conflicting values

            // Debug: Show what PHP is actually using
            $debugIniCommand = "docker exec {$escapedContainer} php -r \"echo 'Loaded: ' . php_ini_loaded_file() . PHP_EOL; echo 'Scanned: ' . php_ini_scanned_files() . PHP_EOL;\" 2>/dev/null";
            if ($server->isNonRoot()) {
                $debugIniCommand = "sudo {$debugIniCommand}";
            }
            $debugInfo = instant_remote_process([$debugIniCommand], $server, false) ?? '';

            // CRITICAL: Store PHP config files in a persistent volume
            // Create/update LocalFileVolume to persist the configuration file
            $confIniFileName = '99-custom-'.$setting.'.ini';
            $confIniFileMountPath = $confDirPath.'/'.$confIniFileName;
            $confIniFileFsPath = './php-config/'.$confIniFileName; // Relative to workdir

            // Prepare file content
            $confContent = "; Custom {$setting} setting - Updated by Coolify\n{$setting} = {$value}\n";

            // Ensure php-config directory exists in workdir
            $workdir = $application->service->workdir();
            $phpConfigDir = $workdir.'/php-config';
            $createDirCommand = "mkdir -p ".escapeshellarg($phpConfigDir);
            if ($server->isNonRoot()) {
                $createDirCommand = "sudo {$createDirCommand}";
            }
            instant_remote_process([$createDirCommand], $server, false);

            try {
                // Find or create LocalFileVolume for this PHP config file
                $fileVolume = LocalFileVolume::where('resource_type', ServiceApplication::class)
                    ->where('resource_id', $application->id)
                    ->where('mount_path', $confIniFileMountPath)
                    ->first();

                if (! $fileVolume) {
                    // Create new file volume
                    $fileVolume = LocalFileVolume::create([
                        'resource_type' => ServiceApplication::class,
                        'resource_id' => $application->id,
                        'fs_path' => $confIniFileFsPath,
                        'mount_path' => $confIniFileMountPath,
                        'is_directory' => false,
                        'content' => $confContent,
                    ]);
                } else {
                    // Update existing file volume
                    $fileVolume->content = $confContent;
                    $fileVolume->save();
                }

                // Save the file to the persistent storage on server
                $fileVolume->saveStorageOnServer();

                // Verify the file was saved correctly on the server
                $workdir = $application->service->workdir();
                $serverFilePath = $workdir.'/php-config/'.$confIniFileName;
                $verifyServerFileCommand = "test -f ".escapeshellarg($serverFilePath)." && cat ".escapeshellarg($serverFilePath)." || echo 'FILE_NOT_FOUND'";
                if ($server->isNonRoot()) {
                    $verifyServerFileCommand = "sudo {$verifyServerFileCommand}";
                }
                $serverFileContent = instant_remote_process([$verifyServerFileCommand], $server, false) ?? '';

                if ($serverFileContent === 'FILE_NOT_FOUND' || empty($serverFileContent)) {
                    $this->dispatch('error', "File was not saved correctly to server volume at {$serverFilePath}. Please check permissions.");
                    return;
                }

                // CRITICAL: Regenerate docker-compose to include the new volume
                // This ensures the volume is mounted when the container restarts
                $this->service->parse();
                $this->service->saveComposeConfigs();

                // Verify the volume is in docker-compose
                $composeContent = $this->service->docker_compose ?? '';
                $volumeInCompose = str_contains($composeContent, 'php-config') && str_contains($composeContent, $confIniFileMountPath);

                // Also write directly to container for immediate effect
                $this->writeToContainerDirectly($server, $escapedContainer, $confDirPath, $confIniFileName, $confContent, $setting, $value);

                // Verify the file exists in the container after writing
                $verifyContainerFileCommand = "docker exec {$escapedContainer} test -f ".escapeshellarg($confIniFileMountPath)." && docker exec {$escapedContainer} cat ".escapeshellarg($confIniFileMountPath)." || echo 'NOT_MOUNTED'";
                if ($server->isNonRoot()) {
                    $verifyContainerFileCommand = "sudo {$verifyContainerFileCommand}";
                }
                $containerFileContent = instant_remote_process([$verifyContainerFileCommand], $server, false) ?? '';

                // Check if file is mounted from volume or written directly
                $checkMountCommand = "docker inspect {$escapedContainer} --format '{{range .Mounts}}{{.Source}}:{{.Destination}} {{end}}' 2>/dev/null | grep -q php-config && echo 'MOUNTED' || echo 'NOT_MOUNTED'";
                if ($server->isNonRoot()) {
                    $checkMountCommand = "sudo {$checkMountCommand}";
                }
                $isMounted = trim(instant_remote_process([$checkMountCommand], $server, false) ?? '');

                // Verify PHP is reading the new value (check both CLI and FPM)
                $verifyPhpCliCommand = "docker exec {$escapedContainer} php -r \"echo ini_get('{$setting}');\" 2>/dev/null";
                if ($server->isNonRoot()) {
                    $verifyPhpCliCommand = "sudo {$verifyPhpCliCommand}";
                }
                $phpCliValue = trim(instant_remote_process([$verifyPhpCliCommand], $server, false) ?? '');

                // Check PHP-FPM value (this is what WordPress uses)
                $verifyPhpFpmCommand = "docker exec {$escapedContainer} sh -c 'php-fpm -i 2>/dev/null | grep \"{$setting}\" | head -1 | awk -F\"=> \" \"{print \\\$2}\" | awk \"{print \\\$1}\" || echo notfound'";
                if ($server->isNonRoot()) {
                    $verifyPhpFpmCommand = "sudo {$verifyPhpFpmCommand}";
                }
                $phpFpmValue = trim(instant_remote_process([$verifyPhpFpmCommand], $server, false) ?? '');
                if ($phpFpmValue === 'notfound' || empty($phpFpmValue)) {
                    $phpFpmValue = $phpCliValue;
                }

                // The file is now persisted in the volume and docker-compose has been updated
                $volumeStatus = $volumeInCompose ? "Volume added to docker-compose." : "WARNING: Volume may not be in docker-compose.";
                $containerStatus = str_contains($containerFileContent, $value) ? "File written to container." : "WARNING: File content not found in container.";
                $mountStatus = ($isMounted === 'MOUNTED') ? "Volume is mounted." : "WARNING: Volume may not be mounted (file written directly to container).";
                $phpStatus = ($phpFpmValue === $value) ? "PHP-FPM reports {$value}." : "WARNING: PHP-FPM reports {$phpFpmValue} instead of {$value}. Service restart required.";

                $this->dispatch('success', "PHP setting {$setting} saved. {$volumeStatus} {$containerStatus} {$mountStatus} {$phpStatus} If PHP-FPM shows old value, restart the SERVICE completely from the service page.");

            } catch (\Throwable $e) {
                $this->dispatch('error', "Failed to save PHP config to persistent volume: ".$e->getMessage().". Trying direct container write...");
                // Fallback to direct container write (non-persistent)
                $this->writeToContainerDirectly($server, $escapedContainer, $confDirPath, $confIniFileName, $confContent, $setting, $value);
            }

            // CRITICAL: Remove or comment out duplicate settings from other conf.d files
            // Files are loaded alphabetically, so 99- prefix ensures our file loads last
            // But we should still check for conflicts
            $listConfFilesCommand = "docker exec {$escapedContainer} find {$confDirPath} -name '*.ini' -type f ! -name '99-custom-{$setting}.ini' 2>/dev/null | sort";
            if ($server->isNonRoot()) {
                $listConfFilesCommand = "sudo {$listConfFilesCommand}";
            }
            $otherConfFiles = explode("\n", trim(instant_remote_process([$listConfFilesCommand], $server, false) ?? ''));

            $conflictingFiles = [];
            foreach ($otherConfFiles as $otherFile) {
                $otherFile = trim($otherFile);
                if (empty($otherFile) || !str_starts_with($otherFile, '/')) {
                    continue;
                }

                // Check if this file has our setting (commented or not)
                $checkSettingCommand = "docker exec {$escapedContainer} grep -E '^[;]*\s*{$setting}\s*=' ".escapeshellarg($otherFile)." 2>/dev/null | head -1";
                if ($server->isNonRoot()) {
                    $checkSettingCommand = "sudo {$checkSettingCommand}";
                }
                $settingLine = trim(instant_remote_process([$checkSettingCommand], $server, false) ?? '');

                if (!empty($settingLine)) {
                    $conflictingFiles[] = $otherFile;
                    // Comment out the setting in this file (our 99- file will override it)
                    $escapedOtherFile = escapeshellarg($otherFile);
                    $commentCommand = "docker exec {$escapedContainer} sed -i 's/^\([;]*\s*\){$escapedSetting}\s*=.*/; \\1{$escapedSetting} = (overridden by 99-custom-{$setting}.ini)/' {$escapedOtherFile}";
                    if ($server->isNonRoot()) {
                        $commentCommand = "sudo {$commentCommand}";
                    }
                    instant_remote_process([$commentCommand], $server, false);
                }
            }

            // Log conflicting files for debugging
            if (!empty($conflictingFiles)) {
                $this->dispatch('warning', "Found conflicting settings in: ".implode(', ', $conflictingFiles).". They have been commented out.");
            }

            // CRITICAL: Also update php.ini file directly (this persists better than conf.d)
            // Since containers are ephemeral, we need to modify the main php.ini file
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

            // Write php.ini back to container using the same reliable method
            try {
                $newContent = implode("\n", $newLines);

                // Save content to a temporary file locally
                $tmpFilename = 'temp/'.uniqid('php-ini-main-').'.ini';
                Storage::disk('local')->put($tmpFilename, $newContent);
                $localTmpPath = Storage::disk('local')->path($tmpFilename);

                // Copy to server temp location
                $serverTmpPath = '/tmp/'.basename($tmpFilename);
                instant_scp($localTmpPath, $serverTmpPath, $server);

                // Copy from server temp to container
                $escapedServerTmp = escapeshellarg($serverTmpPath);
                $copyCommand = "docker cp {$escapedServerTmp} {$escapedContainer}:{$escapedPhpIniPath}";
                if ($server->isNonRoot()) {
                    $copyCommand = "sudo {$copyCommand}";
                }
                instant_remote_process([$copyCommand], $server);

                // Clean up temp files
                Storage::disk('local')->delete($tmpFilename);
                $cleanCommand = "rm -f {$escapedServerTmp}";
                if ($server->isNonRoot()) {
                    $cleanCommand = "sudo {$cleanCommand}";
                }
                instant_remote_process([$cleanCommand], $server, false);
            } catch (\Throwable $e) {
                $this->dispatch('warning', "Failed to update php.ini file: ".$e->getMessage());
            }

            // CRITICAL: Restart PHP-FPM to reload configuration
            // upload_max_filesize and post_max_size require a full PHP-FPM restart, not just reload
            // For these settings, we need to restart the entire container to ensure volume is mounted
            $needsContainerRestart = in_array($setting, ['upload_max_filesize', 'post_max_size', 'memory_limit']);

            if ($needsContainerRestart) {
                // Restart the container to ensure volume is mounted and PHP-FPM reads new config
                $restartContainerCommand = "docker restart {$escapedContainer}";
                if ($server->isNonRoot()) {
                    $restartContainerCommand = "sudo {$restartContainerCommand}";
                }
                instant_remote_process([$restartContainerCommand], $server, false);

                // Wait for container to be ready
                $waitCommand = "docker exec {$escapedContainer} sh -c 'sleep 3 && php -r \"echo \\\"ready\\\";\"' 2>/dev/null || echo 'waiting'";
                if ($server->isNonRoot()) {
                    $waitCommand = "sudo {$waitCommand}";
                }
                $attempts = 0;
                while ($attempts < 10) {
                    $ready = trim(instant_remote_process([$waitCommand], $server, false) ?? '');
                    if ($ready === 'ready') {
                        break;
                    }
                    usleep(500000); // 0.5 seconds
                    $attempts++;
                }
            } else {
                // For other settings, just restart PHP-FPM
                $restartCommands = [
                    "docker exec {$escapedContainer} sh -c 'pkill -9 php-fpm 2>/dev/null || true'",
                    "docker exec {$escapedContainer} sh -c 'service php-fpm restart 2>/dev/null || service php8.3-fpm restart 2>/dev/null || service php8.2-fpm restart 2>/dev/null || service php8.1-fpm restart 2>/dev/null || service php8.0-fpm restart 2>/dev/null || true'",
                ];

                foreach ($restartCommands as $restartCommand) {
                    if ($server->isNonRoot()) {
                        $restartCommand = "sudo {$restartCommand}";
                    }
                    instant_remote_process([$restartCommand], $server, false);
                    usleep(1000000); // 1 second
                }
            }

            // Verify the change was applied - try multiple methods
            $verifiedValue = null;

            // Method 1: Try PHP-FPM directly
            $verifyFpmCommand = "docker exec {$escapedContainer} sh -c 'php-fpm -i 2>/dev/null | grep \"{$setting}\" | head -1 | awk -F\"=> \" \"{print \\\$2}\" | awk \"{print \\\$1}\" || echo notfound'";
            if ($server->isNonRoot()) {
                $verifyFpmCommand = "sudo {$verifyFpmCommand}";
            }
            $fpmValue = trim(instant_remote_process([$verifyFpmCommand], $server, false) ?? '');
            if (!empty($fpmValue) && $fpmValue !== 'notfound') {
                $verifiedValue = $fpmValue;
            }

            // Method 2: Try CLI php
            if (empty($verifiedValue)) {
                $verifyCommand = "docker exec {$escapedContainer} php -r \"echo ini_get('{$setting}');\" 2>/dev/null";
                if ($server->isNonRoot()) {
                    $verifyCommand = "sudo {$verifyCommand}";
                }
                $verifiedValue = trim(instant_remote_process([$verifyCommand], $server, false) ?? '');
            }

            // Method 3: Check conf.d file content directly - multiple methods
            $confFileValue = null;
            $confIniFileForCheck = $confDirPath.'/99-custom-'.$setting.'.ini';
            $escapedConfIniForCheck = escapeshellarg($confIniFileForCheck);

            // Try grep method
            $checkConfCommand = "docker exec {$escapedContainer} grep -E '^{$setting}\s*=' {$escapedConfIniForCheck} 2>/dev/null | head -1 | sed 's/.*=\\s*//' | xargs";
            if ($server->isNonRoot()) {
                $checkConfCommand = "sudo {$checkConfCommand}";
            }
            $confFileValue = trim(instant_remote_process([$checkConfCommand], $server, false) ?? '');

            // If empty, try reading entire file and parsing
            if (empty($confFileValue)) {
                $readFileCommand = "docker exec {$escapedContainer} cat {$escapedConfIniForCheck} 2>/dev/null";
                if ($server->isNonRoot()) {
                    $readFileCommand = "sudo {$readFileCommand}";
                }
                $fileContent = instant_remote_process([$readFileCommand], $server, false) ?? '';
                if (!empty($fileContent) && preg_match('/'.$setting.'\s*=\s*([^\s]+)/', $fileContent, $matches)) {
                    $confFileValue = trim($matches[1]);
                }
            }

            // Reload settings to update UI
            $this->loadPhpIniSettings($this->selectedContainerForPhpIni);

            // Note: Success message is already dispatched in the try block above
            // This section is only reached if there's an error or if we need additional verification
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error updating PHP setting: '.$e->getMessage());
        }
    }

    private function writeToContainerDirectly($server, $escapedContainer, $confDirPath, $confIniFileName, $confContent, $setting, $value)
    {
        // Fallback method: write directly to container (non-persistent)
        $confIniFile = $confDirPath.'/'.$confIniFileName;
        $escapedConfIni = escapeshellarg($confIniFile);

        try {
            $tmpFilename = 'temp/'.uniqid('php-ini-').'.ini';
            Storage::disk('local')->put($tmpFilename, $confContent);
            $localTmpPath = Storage::disk('local')->path($tmpFilename);

            $serverTmpPath = '/tmp/'.basename($tmpFilename);
            instant_scp($localTmpPath, $serverTmpPath, $server);

            $escapedServerTmp = escapeshellarg($serverTmpPath);
            $copyCommand = "docker cp {$escapedServerTmp} {$escapedContainer}:{$escapedConfIni}";
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
            $this->dispatch('error', "Fallback write also failed: ".$e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.project.service.wordpress-manager');
    }
}
