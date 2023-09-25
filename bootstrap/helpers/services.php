<?php

use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Support\Str;

function replaceRegex(?string $name = null)
{
    return "/\\\${?{$name}[^}]*}?|\\\${$name}\w+/";
}
function collectRegex(string $name)
{
    return "/{$name}\w+/";
}
function replaceVariables($variable)
{
    return $variable->replaceFirst('$', '')->replaceFirst('{', '')->replaceLast('}', '');
}

function serviceStatus(Service $service)
{
    $foundRunning = false;
    $isDegraded = false;
    $applications = $service->applications;
    $databases = $service->databases;
    foreach ($applications as $application) {
        if (Str::of($application->status)->startsWith('running')) {
            $foundRunning = true;
        } else {
            $isDegraded = true;
        }
    }
    foreach ($databases as $database) {
        if (Str::of($database->status)->startsWith('running')) {
            $foundRunning = true;
        } else {
            $isDegraded = true;
        }
    }
    if ($foundRunning && !$isDegraded) {
        return 'running';
    } else if ($foundRunning && $isDegraded) {
        return 'degraded';
    } else if (!$foundRunning && $isDegraded) {
        return 'exited';
    }
    return 'exited';
}
function saveFileVolumesHelper(ServiceApplication|ServiceDatabase $oneService)
{
    try {
        $workdir = $oneService->service->workdir();
        $server = $oneService->service->server;
        $applicationFileVolume = $oneService->fileStorages()->get();
        $commands = collect([
            "mkdir -p $workdir",
            "cd $workdir"
        ]);
        foreach ($applicationFileVolume as $fileVolume) {
            $content = $fileVolume->content;
            $path = Str::of($fileVolume->fs_path);
            $dir = $path->beforeLast('/');
            if ($dir->startsWith('.')) {
                $dir = $dir->after('.');
                $dir = $workdir . $dir;
            }
            $content = base64_encode($content);
            $commands->push("mkdir -p $dir");
            $commands->push("echo '$content' | base64 -d > $path");
        }
        return instant_remote_process($commands, $server);
    } catch (\Throwable $e) {
        return handleError($e);
    }
}
