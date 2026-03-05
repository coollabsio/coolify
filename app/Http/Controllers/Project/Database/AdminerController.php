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
        $adminerModifiedPath = public_path('adminer_modified.php');

        // Delete corrupted modified file if it exists (will be regenerated)
        if (file_exists($adminerModifiedPath)) {
            // Try to validate PHP syntax by checking if file starts with <?php
            $testContent = @file_get_contents($adminerModifiedPath);
            if ($testContent === false || strpos($testContent, '<?php') !== 0) {
                // File is corrupted or invalid, delete it
                @unlink($adminerModifiedPath);
            } else {
                // Check if original is newer than modified
                if (file_exists($adminerPath) && filemtime($adminerPath) > filemtime($adminerModifiedPath)) {
                    @unlink($adminerModifiedPath);
                }
            }
        }

        // Check if we need to download or modify Adminer
        $needsModification = !file_exists($adminerModifiedPath);

        if ($needsModification) {
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

            // Read Adminer and modify it to remove problematic headers
            $adminerContent = file_get_contents($adminerPath);

            // Process line by line to safely comment out problematic header() calls
            // This is safer than regex replacements that might break PHP syntax
            $lines = explode("\n", $adminerContent);
            $modifiedLines = [];

            foreach ($lines as $line) {
                $originalLine = $line;
                $trimmedLine = trim($line);

                // Check if this line contains X-Frame-Options: deny header call
                if (preg_match('/header\s*\(/i', $trimmedLine) &&
                    preg_match('/X-Frame-Options/i', $trimmedLine) &&
                    preg_match('/deny/i', $trimmedLine)) {
                    // Comment out the entire line
                    $modifiedLines[] = '// ' . $originalLine . ' // Removed to allow iframe embedding';
                    continue;
                }

                // Check if this line contains CSP header with frame-src 'none'
                if (preg_match('/header\s*\(/i', $trimmedLine) &&
                    preg_match('/Content-Security-Policy/i', $trimmedLine) &&
                    preg_match('/frame-src[\'"]*\s*none/i', $trimmedLine)) {
                    // Comment out the entire line
                    $modifiedLines[] = '// ' . $originalLine . ' // Modified to allow iframe embedding';
                    continue;
                }

                // Keep the line as-is
                $modifiedLines[] = $originalLine;
            }

            $adminerContent = implode("\n", $modifiedLines);

            // Save modified version
            file_put_contents($adminerModifiedPath, $adminerContent);
        }

        // Use modified Adminer file
        $adminerPath = $adminerModifiedPath;

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

        // Set headers BEFORE executing Adminer so they have priority
        // This prevents Adminer from blocking iframe embedding
        header('X-Frame-Options: SAMEORIGIN', true); // true = replace existing
        header('Content-Security-Policy: script-src \'self\' \'unsafe-inline\' \'unsafe-eval\'; connect-src \'self\' https://www.adminer.org; frame-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'', true);

        // Execute Adminer and capture output
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

            // Immediately remove headers that Adminer set before they're sent
            // This must be done right after ob_get_clean() and before any output
            @header_remove('X-Frame-Options');
            @header_remove('Content-Security-Policy');
            @header_remove('X-Content-Type-Options');
            @header_remove('X-XSS-Protection');

            // If output is empty or looks like PHP code, something went wrong
            if (empty($adminerContent) || (strpos($adminerContent, '<?php') === 0 && strpos($adminerContent, '<!DOCTYPE') === false && strpos($adminerContent, '<html') === false)) {
                throw new \Exception('Adminer did not output valid HTML');
            }

            // Remove problematic meta tags from Adminer's HTML that block iframes
            // Remove CSP meta tags that have frame-src 'none'
            $adminerContent = preg_replace('/<meta[^>]*http-equiv=["\']Content-Security-Policy["\'][^>]*>/i', '', $adminerContent);
            // Remove X-Frame-Options meta tags
            $adminerContent = preg_replace('/<meta[^>]*http-equiv=["\']X-Frame-Options["\'][^>]*>/i', '', $adminerContent);
        } catch (\Throwable $e) {
            $output = ob_get_clean();
            // If we got some output despite the error, use it
            if (!empty($output) && strpos($output, '<!DOCTYPE') !== false) {
                $adminerContent = $output;
                // Remove problematic meta tags
                $adminerContent = preg_replace('/<meta[^>]*http-equiv=["\']Content-Security-Policy["\'][^>]*>/i', '', $adminerContent);
                $adminerContent = preg_replace('/<meta[^>]*http-equiv=["\']X-Frame-Options["\'][^>]*>/i', '', $adminerContent);
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

        // Return response with proper headers to allow iframe embedding
        // Adminer sets its own headers, so we need to override them completely
        $response = response($adminerContent)
            ->header('Content-Type', 'text/html; charset=utf-8');

        // Remove ALL headers that Adminer may have set
        $response->headers->remove('X-Frame-Options');
        $response->headers->remove('Content-Security-Policy');
        $response->headers->remove('X-Content-Type-Options');
        $response->headers->remove('X-XSS-Protection');

        // Set our own headers that allow iframe embedding
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN', false);

        // Modify CSP to allow frames from same origin
        $csp = "script-src 'self' 'unsafe-inline' 'unsafe-eval'; connect-src 'self' https://www.adminer.org; frame-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'";
        $response->headers->set('Content-Security-Policy', $csp, false);

        // Set other security headers
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
