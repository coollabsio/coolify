<?php

use App\Models\EnvironmentVariable;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

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
    $foundRestaring = false;
    $applications = $service->applications;
    $databases = $service->databases;
    foreach ($applications as $application) {
        if ($application->exclude_from_status) {
            continue;
        }
        if (Str::of($application->status)->startsWith('running')) {
            $foundRunning = true;
        } else if (Str::of($application->status)->startsWith('restarting')) {
            $foundRestaring = true;
        } else {
            $isDegraded = true;
        }
    }
    foreach ($databases as $database) {
        if ($database->exclude_from_status) {
            continue;
        }
        if (Str::of($database->status)->startsWith('running')) {
            $foundRunning = true;
        } else if (Str::of($database->status)->startsWith('restarting')) {
            $foundRestaring = true;
        } else {
            $isDegraded = true;
        }
    }
    if ($foundRestaring) {
        return 'degraded';
    }
    if ($foundRunning && !$isDegraded) {
        return 'running';
    } else if ($foundRunning && $isDegraded) {
        return 'degraded';
    } else if (!$foundRunning && !$isDegraded) {
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
            "mkdir -p $workdir > /dev/null 2>&1 || true",
            "cd $workdir"
        ]);
        foreach ($applicationFileVolume as $fileVolume) {
            $path = Str::of($fileVolume->fs_path);
            if ($fileVolume->is_directory) {
                $commands->push("test -f $path && rm -f $path > /dev/null 2>&1 || true");
                $commands->push("mkdir -p $path > /dev/null 2>&1 || true");
                continue;
            }
            $content = $fileVolume->content;
            $dir = $path->beforeLast('/');
            if ($dir->startsWith('.')) {
                $dir = $dir->after('.');
                $dir = $workdir . $dir;
            }
            $content = base64_encode($content);
            $commands->push("test -d $path && rm -rf $path > /dev/null 2>&1 || true");
            $commands->push("mkdir -p $dir > /dev/null 2>&1 || true");
            $commands->push("echo '$content' | base64 -d > $path");
        }
        return instant_remote_process($commands, $server);
    } catch (\Throwable $e) {
        return handleError($e);
    }
}
function updateCompose($resource) {
    try {
        $name = data_get($resource, 'name');
        $dockerComposeRaw = data_get($resource, 'service.docker_compose_raw');
        $dockerCompose = Yaml::parse($dockerComposeRaw);

        // Switch Image
        $image = data_get($resource, 'image');
        data_set($dockerCompose, "services.{$name}.image", $image);

        // Update FQDN
        $variableName = "SERVICE_FQDN_" . Str::of($resource->name)->upper();
        ray($variableName);
        $generatedEnv = EnvironmentVariable::where('service_id', $resource->service_id)->where('key', $variableName)->first();
        if ($generatedEnv){
            $generatedEnv->value = $resource->fqdn;
            $generatedEnv->save();
        }


        $dockerComposeRaw = Yaml::dump($dockerCompose, 10, 2);
        $resource->service->docker_compose_raw = $dockerComposeRaw;
        $resource->service->save();
    } catch (\Throwable $e) {
        return handleError($e);
    }
}
