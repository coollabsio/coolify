<?php

use App\Models\EnvironmentVariable;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Support\Str;
use Spatie\Url\Url;
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
            // Exists and is a file
            $isFile = instant_remote_process(["test -f $fileLocation && echo OK || echo NOK"], $server);
            // Exists and is a directory
            $isDir = instant_remote_process(["test -d $fileLocation && echo OK || echo NOK"], $server);

            if ($isFile == 'OK') {
                // If its a file & exists
                $filesystemContent = instant_remote_process(["cat $fileLocation"], $server);
                $fileVolume->content = $filesystemContent;
                $fileVolume->is_directory = false;
                $fileVolume->save();
            } else if ($isDir == 'OK') {
                // If its a directory & exists
                $fileVolume->content = null;
                $fileVolume->is_directory = true;
                $fileVolume->save();
            } else if ($isFile == 'NOK' && $isDir == 'NOK' && !$fileVolume->is_directory && $isInit && $content) {
                // Does not exists (no dir or file), not flagged as directory, is init, has content
                $fileVolume->content = $content;
                $fileVolume->is_directory = false;
                $fileVolume->save();
                $content = base64_encode($content);
                $dir = Str::of($fileLocation)->dirname();
                instant_remote_process([
                    "mkdir -p $dir",
                    "echo '$content' | base64 -d > $fileLocation"
                ], $server);
            } else if ($isFile == 'NOK' && $isDir == 'NOK' && $fileVolume->is_directory && $isInit) {
                $fileVolume->content = null;
                $fileVolume->is_directory = true;
                $fileVolume->save();
                instant_remote_process(["mkdir -p $fileLocation"], $server);
            }
        }
    } catch (\Throwable $e) {
        return handleError($e);
    }
}
function updateCompose(ServiceApplication|ServiceDatabase $resource)
{
    try {
        $name = data_get($resource, 'name');
        $dockerComposeRaw = data_get($resource, 'service.docker_compose_raw');
        $dockerCompose = Yaml::parse($dockerComposeRaw);

        // Switch Image
        $image = data_get($resource, 'image');
        data_set($dockerCompose, "services.{$name}.image", $image);
        $dockerComposeRaw = Yaml::dump($dockerCompose, 10, 2);
        $resource->service->docker_compose_raw = $dockerComposeRaw;
        $resource->service->save();

        if ($resource->fqdn && !str($resource->fqdn)->contains(',')) {
            // Update FQDN
            $variableName = "SERVICE_FQDN_" . Str::of($resource->name)->upper();
            $generatedEnv = EnvironmentVariable::where('service_id', $resource->service_id)->where('key', $variableName)->first();
            $fqdn = Url::fromString($resource->fqdn);
            $fqdn = $fqdn->getScheme() . '://' . $fqdn->getHost();
            if ($generatedEnv) {
                $generatedEnv->value = $fqdn;
                $generatedEnv->save();
            }
            $variableName = "SERVICE_URL_" . Str::of($resource->name)->upper();
            $generatedEnv = EnvironmentVariable::where('service_id', $resource->service_id)->where('key', $variableName)->first();
            $url = Url::fromString($resource->fqdn);
            $url = $url->getHost();
            if ($generatedEnv) {
                $url = Str::of($resource->fqdn)->after('://');
                $generatedEnv->value = $url;
                $generatedEnv->save();
            }
        }

    } catch (\Throwable $e) {
        return handleError($e);
    }
}
