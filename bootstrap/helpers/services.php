<?php

use App\Models\Application;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Support\Stringable;
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
function replaceVariables(string $variable): Stringable
{
    return str($variable)->before('}')->replaceFirst('$', '')->replaceFirst('{', '');
}

function getFilesystemVolumesFromServer(ServiceApplication|ServiceDatabase|Application $oneService, bool $isInit = false)
{
    try {
        if ($oneService->getMorphClass() === \App\Models\Application::class) {
            $workdir = $oneService->workdir();
            $server = $oneService->destination->server;
        } else {
            $workdir = $oneService->service->workdir();
            $server = $oneService->service->server;
        }
        $fileVolumes = $oneService->fileStorages()->get();
        $commands = collect([
            "mkdir -p $workdir > /dev/null 2>&1 || true",
            "cd $workdir",
        ]);
        instant_remote_process($commands, $server);
        foreach ($fileVolumes as $fileVolume) {
            $path = str(data_get($fileVolume, 'fs_path'));
            $content = data_get($fileVolume, 'content');
            if ($path->startsWith('.')) {
                $path = $path->after('.');
                $fileLocation = $workdir.$path;
            } else {
                $fileLocation = $path;
            }
            // Exists and is a file
            $isFile = instant_remote_process(["test -f $fileLocation && echo OK || echo NOK"], $server);
            // Exists and is a directory
            $isDir = instant_remote_process(["test -d $fileLocation && echo OK || echo NOK"], $server);

            if ($isFile === 'OK') {
                // If its a file & exists
                $filesystemContent = instant_remote_process(["cat $fileLocation"], $server);
                if ($fileVolume->is_based_on_git) {
                    $fileVolume->content = $filesystemContent;
                }
                $fileVolume->is_directory = false;
                $fileVolume->save();
            } elseif ($isDir === 'OK') {
                // If its a directory & exists
                $fileVolume->content = null;
                $fileVolume->is_directory = true;
                $fileVolume->save();
            } elseif ($isFile === 'NOK' && $isDir === 'NOK' && ! $fileVolume->is_directory && $isInit && $content) {
                // Does not exists (no dir or file), not flagged as directory, is init, has content
                $fileVolume->content = $content;
                $fileVolume->is_directory = false;
                $fileVolume->save();
                $content = base64_encode($content);
                $dir = str($fileLocation)->dirname();
                instant_remote_process([
                    "mkdir -p $dir",
                    "echo '$content' | base64 -d | tee $fileLocation",
                ], $server);
            } elseif ($isFile === 'NOK' && $isDir === 'NOK' && $fileVolume->is_directory && $isInit) {
                // Does not exists (no dir or file), flagged as directory, is init
                $fileVolume->content = null;
                $fileVolume->is_directory = true;
                $fileVolume->save();
                instant_remote_process(["mkdir -p $fileLocation"], $server);
            } elseif ($isFile === 'NOK' && $isDir === 'NOK' && ! $fileVolume->is_directory && $isInit && is_null($content)) {
                // Does not exists (no dir or file), not flagged as directory, is init, has no content => create directory
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
        if (! $dockerComposeRaw) {
            throw new \Exception('No compose file found or not a valid YAML file.');
        }
        $dockerCompose = Yaml::parse($dockerComposeRaw);

        // Switch Image
        $updatedImage = data_get_str($resource, 'image');
        $currentImage = data_get_str($dockerCompose, "services.{$name}.image");
        if ($currentImage !== $updatedImage) {
            data_set($dockerCompose, "services.{$name}.image", $updatedImage->value());
            $dockerComposeRaw = Yaml::dump($dockerCompose, 10, 2);
            $resource->service->docker_compose_raw = $dockerComposeRaw;
            $resource->service->save();
            $resource->image = $updatedImage;
            $resource->save();
        }

        $serviceName = str($resource->name)->upper()->replace('-', '_')->replace('.', '_');
        $resource->service->environment_variables()->where('key', 'LIKE', "SERVICE_FQDN_{$serviceName}%")->delete();
        $resource->service->environment_variables()->where('key', 'LIKE', "SERVICE_URL_{$serviceName}%")->delete();

        if ($resource->fqdn) {
            $resourceFqdns = str($resource->fqdn)->explode(',');
            $resourceFqdns = $resourceFqdns->first();
            $variableName = 'SERVICE_URL_'.str($resource->name)->upper()->replace('-', '_')->replace('.', '_');
            $url = Url::fromString($resourceFqdns);
            $port = $url->getPort();
            $path = $url->getPath();
            $urlValue = $url->getScheme().'://'.$url->getHost();
            $urlValue = ($path === '/') ? $urlValue : $urlValue.$path;
            $resource->service->environment_variables()->updateOrCreate([
                'resourceable_type' => Service::class,
                'resourceable_id' => $resource->service_id,
                'key' => $variableName,
            ], [
                'value' => $urlValue,
                'is_preview' => false,
            ]);
            if ($port) {
                $variableName = $variableName."_$port";
                $resource->service->environment_variables()->updateOrCreate([
                    'resourceable_type' => Service::class,
                    'resourceable_id' => $resource->service_id,
                    'key' => $variableName,
                ], [
                    'value' => $urlValue,
                    'is_preview' => false,
                ]);
            }
            $variableName = 'SERVICE_FQDN_'.str($resource->name)->upper()->replace('-', '_')->replace('.', '_');
            $fqdn = Url::fromString($resourceFqdns);
            $port = $fqdn->getPort();
            $path = $fqdn->getPath();
            $fqdn = $fqdn->getHost();
            $fqdnValue = str($fqdn)->after('://');
            if ($path !== '/') {
                $fqdnValue = $fqdnValue.$path;
            }
            $resource->service->environment_variables()->updateOrCreate([
                'resourceable_type' => Service::class,
                'resourceable_id' => $resource->service_id,
                'key' => $variableName,
            ], [
                'value' => $fqdnValue,
                'is_preview' => false,
            ]);
            if ($port) {
                $variableName = $variableName."_$port";
                $resource->service->environment_variables()->updateOrCreate([
                    'resourceable_type' => Service::class,
                    'resourceable_id' => $resource->service_id,
                    'key' => $variableName,
                ], [
                    'value' => $fqdnValue,
                    'is_preview' => false,
                ]);
            }
        }
    } catch (\Throwable $e) {
        return handleError($e);
    }
}
function serviceKeys()
{
    return get_service_templates()->keys();
}
