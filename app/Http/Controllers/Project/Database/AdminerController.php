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

        // Read Adminer file
        $adminerContent = file_get_contents($adminerPath);

        // Adminer accepts URL parameters for auto-login: ?server=localhost&username=root&password=xxx&driver=mysql
        // We'll inject JavaScript to auto-fill and submit the login form
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

        // Inject script before closing body tag
        $adminerContent = str_replace('</body>', $autoLoginScript.'</body>', $adminerContent);

        return response($adminerContent)->header('Content-Type', 'text/html');
    }
}
