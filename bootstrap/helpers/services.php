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
function getFilesystemVolumesFromServer(ServiceApplication|ServiceDatabase $oneService, bool $isInit = false)
{
    // TODO: make this async
    try {
        $workdir = $oneService->service->workdir();
        $server = $oneService->service->server;
        $fileVolumes = $oneService->fileStorages()->get();
        $commands = collect([
            "mkdir -p $workdir > /dev/null 2>&1 || true",
            "cd "
        ]);
        instant_remote_process($commands, $server);
        foreach ($fileVolumes as $fileVolume) {
            $path = Str::of(data_get($fileVolume, 'fs_path'));
            $content = data_get($fileVolume, 'content');
            if ($path->startsWith('.')) {
                $path = $path->after('.');
                $fileLocation = $workdir . $path;
            } else {
                $fileLocation = $path;
            }
            $isFile = instant_remote_process(["test -f $fileLocation && echo OK || echo NOK"], $server);
            $isDir = instant_remote_process(["test -d $fileLocation && echo OK || echo NOK"], $server);
            if ($isFile === 'NOK' &&!$fileVolume->is_directory && $isInit) {
                $fileVolume->saveStorageOnServer($oneService);
                continue;
            }
            if ($isFile == 'OK' && !$fileVolume->is_directory) {
                $filesystemContent = instant_remote_process(["cat $fileLocation"], $server);
                if (base64_encode($filesystemContent) != base64_encode($content)) {
                    $fileVolume->content = $filesystemContent;
                    $fileVolume->save();
                }
            } else {
                if ($isDir == 'OK') {
                    $fileVolume->content = null;
                    $fileVolume->is_directory = true;
                    $fileVolume->save();
                } else {
                    $fileVolume->content = null;
                    $fileVolume->is_directory = false;
                    $fileVolume->save();
                }
            }
        }
    } catch (\Throwable $e) {
        return handleError($e);
    }
}
function updateCompose($resource)
{
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
        if ($generatedEnv) {
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
