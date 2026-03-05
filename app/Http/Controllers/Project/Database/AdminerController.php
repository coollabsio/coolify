<?php

namespace App\Http\Controllers\Project\Database;

use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\Request;

class AdminerController extends Controller
{
    public function index(Request $request)
    {
        $container = $request->query('container');
        $serverId = $request->query('server_id');

        // Validate container is provided
        if (! $container) {
            abort(404, 'Container not specified.');
        }

        // Validate server_id is provided (0 is valid for Coolify host)
        if ($serverId === null || $serverId === '') {
            abort(404, 'Server ID not specified.');
        }

        // Convert to integer for comparison
        $serverId = (int) $serverId;

        try {
            // Server ID 0 is valid (Coolify host), so we use find() instead of findOrFail()
            // and check if it exists
            $server = Server::find($serverId);
            if (! $server) {
                abort(404, 'Server not found with ID: '.$serverId);
            }
        } catch (\Exception $e) {
            abort(404, 'Server not found: '.$e->getMessage());
        }

        // Download Adminer if not exists (store in public directory for easier access)
        $adminerPath = public_path('adminer.php');
        if (! file_exists($adminerPath)) {
            try {
                $adminerContent = @file_get_contents('https://www.adminer.org/latest.php');
                if ($adminerContent === false) {
                    abort(500, 'Failed to download Adminer. Please check your internet connection.');
                }
                file_put_contents($adminerPath, $adminerContent);
            } catch (\Exception $e) {
                abort(500, 'Failed to download Adminer: '.$e->getMessage());
            }
        }

        // Get database credentials from container
        $escapedContainer = escapeshellarg($container);
        $command = "docker exec {$escapedContainer} env";
        if ($server->isNonRoot()) {
            $command = "sudo {$command}";
        }
        $envOutput = instant_remote_process([$command], $server, false) ?? '';

        // Extract credentials
        preg_match('/MYSQL_ROOT_PASSWORD=([^\n]+)/', $envOutput, $mysqlMatches);
        preg_match('/MARIADB_ROOT_PASSWORD=([^\n]+)/', $envOutput, $mariadbMatches);
        preg_match('/MYSQL_DATABASE=([^\n]+)/', $envOutput, $dbMatches);

        $password = $mariadbMatches[1] ?? $mysqlMatches[1] ?? '';
        $database = $dbMatches[1] ?? '';
        $isMariaDB = ! empty($mariadbMatches[1]) || str_contains(strtolower($container), 'mariadb');

        // Execute Adminer and capture output
        // Adminer latest.php is a single PHP file that outputs HTML
        // We need to execute it within an output buffer to capture the HTML
        ob_start();
        ob_implicit_flush(0);
        
        try {
            // Set up Adminer environment variables for auto-login via URL parameters
            // Adminer will read these from $_GET
            $_GET['server'] = 'localhost';
            $_GET['username'] = 'root';
            $_GET['password'] = $password;
            $_GET['driver'] = $isMariaDB ? 'mariadb' : 'mysql';
            if ($database) {
                $_GET['db'] = $database;
            }

            // Temporarily change directory to adminer's location to handle relative paths
            $oldCwd = getcwd();
            chdir(dirname($adminerPath));

            // Include Adminer file (it will execute and output HTML)
            // Suppress errors to prevent them from breaking the output
            $oldErrorReporting = error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
            include basename($adminerPath);
            error_reporting($oldErrorReporting);
            
            // Restore directory
            chdir($oldCwd);
            
            $adminerContent = ob_get_clean();
            
            // If output is empty or looks like PHP code, something went wrong
            if (empty($adminerContent) || (strpos($adminerContent, '<?php') === 0 && strpos($adminerContent, '<!DOCTYPE') === false && strpos($adminerContent, '<html') === false)) {
                throw new \Exception('Adminer did not output valid HTML');
            }
        } catch (\Throwable $e) {
            $output = ob_get_clean();
            // If we got some output despite the error, use it
            if (!empty($output) && strpos($output, '<!DOCTYPE') !== false) {
                $adminerContent = $output;
            } else {
                // If no valid output, abort with error
                abort(500, 'Failed to execute Adminer: '.$e->getMessage());
            }
        }

        // Adminer accepts URL parameters for auto-login: ?server=localhost&username=root&password=xxx&driver=mysql
        // We'll inject JavaScript to auto-fill and submit the login form as a fallback
        $autoLoginScript = "
        <script>
        window.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                var serverInput = document.querySelector('input[name=\"auth[server]\"]') || document.querySelector('input[name=\"server\"]');
                var usernameInput = document.querySelector('input[name=\"auth[username]\"]') || document.querySelector('input[name=\"username\"]');
                var passwordInput = document.querySelector('input[name=\"auth[password]\"]') || document.querySelector('input[name=\"password\"]');
                var driverSelect = document.querySelector('select[name=\"auth[driver]\"]') || document.querySelector('select[name=\"driver\"]');
                var form = document.querySelector('form');
                
                if (serverInput && usernameInput && passwordInput && form) {
                    serverInput.value = 'localhost';
                    usernameInput.value = 'root';
                    passwordInput.value = ".json_encode($password).";
                    if (driverSelect) {
                        driverSelect.value = ".json_encode($isMariaDB ? 'mariadb' : 'mysql').";
                    }
                    form.submit();
                }
            }, 500);
        });
        </script>";

        // Inject script before closing body tag (if exists) or at the end
        if (strpos($adminerContent, '</body>') !== false) {
            $adminerContent = str_replace('</body>', $autoLoginScript.'</body>', $adminerContent);
        } else {
            // If no body tag, append at the end
            $adminerContent .= $autoLoginScript;
        }

        return response($adminerContent)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
