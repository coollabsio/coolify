<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\Support\Facades\Gate;

class FileBrowserController extends Controller
{
    public function download(string $service_uuid)
    {
        $filePath = request()->query('path');
        
        if (empty($filePath)) {
            return response()->json(['message' => 'File path required'], 400);
        }
        
        $service = Service::where('uuid', $service_uuid)->firstOrFail();
        
        // Authorize
        Gate::authorize('update', $service);
        
        $server = $service->server;
        
        // Get container name from service
        $containers = data_get($service, 'docker_compose', []);
        if (is_array($containers) && !empty($containers)) {
            $containerName = array_key_first($containers);
        } else {
            return response()->json(['message' => 'Container not found'], 404);
        }
        
        $escapedPath = escapeshellarg($filePath);
        $command = collect([
            "docker exec {$containerName} cat {$escapedPath} 2>&1"
        ]);
        
        $content = instant_remote_process($command, $server, false);
        
        if (!$content) {
            return response()->json(['message' => 'Failed to download file'], 500);
        }
        
        $fileName = basename($filePath);
        
        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $fileName, [
            'Content-Type' => 'application/octet-stream',
        ]);
    }
}
