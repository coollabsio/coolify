<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Activitylog\Models\Activity;

class InstallDocker
{
    use AsAction;

    public function handle(Server $server, Activity $activity)
    {
        $user = $server->user;
        
        // Add retry logic for SSH connections with exponential backoff
        $maxRetries = 3;
        $retryDelay = 1; // seconds
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                // Ensure we're using the correct SSH key for this server
                $server->refresh(); // Refresh server model to get latest SSH key data
                
                $dockerVersion = instant_remote_process([
                    'docker version --format "{{.Server.Version}}"'
                ], $server, false);
                
                if ($dockerVersion) {
                    $activity->description = $activity->description . " Docker already installed.";
                    return;
                }
                
                // Docker installation commands
                $commands = [
                    'curl -fsSL https://get.docker.com | sh',
                    'usermod -aG docker ' . $server->user,
                    'systemctl enable docker',
                    'systemctl start docker'
                ];
                
                foreach ($commands as $command) {
                    instant_remote_process([$command], $server, throw: true);
                }
                
                // Success - break out of retry loop
                break;
                
            } catch (\Exception $e) {
                if ($attempt === $maxRetries) {
                    // On final attempt, re-throw the exception
                    throw $e;
                }
                
                // Check if it's an SSH authentication error
                if (str_contains($e->getMessage(), 'Permission denied') && 
                    str_contains($e->getMessage(), 'publickey')) {
                    
                    // Log the retry attempt
                    ray("SSH auth failed on attempt {$attempt}, retrying in {$retryDelay} seconds");
                    
                    // Wait before retrying with exponential backoff
                    sleep($retryDelay);
                    $retryDelay *= 2;
                    
                    // Clear any cached SSH connections for this server
                    $this->clearServerSshCache($server);
                    
                    continue;
                } else {
                    // For non-SSH errors, don't retry
                    throw $e;
                }
            }
        }
    }
    
    private function clearServerSshCache(Server $server)
    {
        // Clear any cached SSH connections or keys for this server
        // This helps ensure we get fresh SSH connection attempts
        if (function_exists('ssh2_disconnect')) {
            // If using SSH2 extension, we might need to clear connections
            // Implementation depends on how SSH connections are cached
        }
        
        // Force garbage collection to clear any lingering connections
        gc_collect_cycles();
    }
}