<?php

use App\Models\Application;
use App\Models\EnvironmentVariable;
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
    return $variable->before('}')->replaceFirst('$', '')->replaceFirst('{', '');
}

function getFilesystemVolumesFromServer(ServiceApplication|ServiceDatabase|Application $oneService, bool $isInit = false)
{
    try {
        if ($oneService->getMorphClass() === 'App\Models\Application') {
            $workdir = $oneService->workdir();
            $server = $oneService->destination->server;
        } else {
            $workdir = $oneService->service->workdir();
            $server = $oneService->service->server;
        }
        $fileVolumes = $oneService->fileStorages()->get();
        $commands = collect([
            "mkdir -p $workdir > /dev/null 2>&1 || true",
            "cd $workdir"
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
                    "echo '$content' | base64 -d | tee $fileLocation"
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
        if ($resource->fqdn) {
            $resourceFqdns = str($resource->fqdn)->explode(',');
            if ($resourceFqdns->count() === 1) {
                $resourceFqdns = $resourceFqdns->first();
                $variableName = "SERVICE_FQDN_" . Str::of($resource->name)->upper()->replace('-', '');
                $generatedEnv = EnvironmentVariable::where('service_id', $resource->service_id)->where('key', $variableName)->first();
                $fqdn = Url::fromString($resourceFqdns);
                $port = $fqdn->getPort();
                $fqdn = $fqdn->getScheme() . '://' . $fqdn->getHost();
                if ($generatedEnv) {
                    $generatedEnv->value = $fqdn;
                    $generatedEnv->save();
                }
                if ($port) {
                    $variableName = $variableName . "_$port";
                    $generatedEnv = EnvironmentVariable::where('service_id', $resource->service_id)->where('key', $variableName)->first();
                    if ($generatedEnv) {
                        $generatedEnv->value = $fqdn . ':' . $port;
                        $generatedEnv->save();
                    }
                }
                $variableName = "SERVICE_URL_" . Str::of($resource->name)->upper()->replace('-', '');
                $generatedEnv = EnvironmentVariable::where('service_id', $resource->service_id)->where('key', $variableName)->first();
                $url = Url::fromString($fqdn);
                $port = $url->getPort();
                $url = $url->getHost();
                if ($generatedEnv) {
                    $url = Str::of($fqdn)->after('://');
                    $generatedEnv->value = $url;
                    $generatedEnv->save();
                }
                if ($port) {
                    $variableName = $variableName . "_$port";
                    $generatedEnv = EnvironmentVariable::where('service_id', $resource->service_id)->where('key', $variableName)->first();
                    if ($generatedEnv) {
                        $generatedEnv->value = $url . ':' . $port;
                        $generatedEnv->save();
                    }
                }
            } else if ($resourceFqdns->count() > 1) {
                foreach ($resourceFqdns as $fqdn) {
                    $host = Url::fromString($fqdn);
                    $port = $host->getPort();
                    $url = $host->getHost();
                    $host = $host->getScheme() . '://' . $host->getHost();
                    if ($port) {
                        $port_envs = EnvironmentVariable::where('service_id', $resource->service_id)->where('key', 'like', "SERVICE_FQDN_%_$port")->get();
                        foreach ($port_envs as $port_env) {
                            $service_fqdn = str($port_env->key)->beforeLast('_')->after('SERVICE_FQDN_');
                            $env = EnvironmentVariable::where('service_id', $resource->service_id)->where('key', 'SERVICE_FQDN_' . $service_fqdn)->first();
                            if ($env) {
                                $env->value = $host;
                                $env->save();
                            }
                            $port_env->value = $host . ':' . $port;
                            $port_env->save();
                        }
                        $port_envs_url = EnvironmentVariable::where('service_id', $resource->service_id)->where('key', 'like', "SERVICE_URL_%_$port")->get();
                        foreach ($port_envs_url as $port_env_url) {
                            $service_url = str($port_env_url->key)->beforeLast('_')->after('SERVICE_URL_');
                            $env = EnvironmentVariable::where('service_id', $resource->service_id)->where('key', 'SERVICE_URL_' . $service_url)->first();
                            if ($env) {
                                $env->value = $url;
                                $env->save();
                            }
                            $port_env_url->value = $url . ':' . $port;
                            $port_env_url->save();
                        }
                    } else {
                        $variableName = "SERVICE_FQDN_" . Str::of($resource->name)->upper()->replace('-', '');
                        $generatedEnv = EnvironmentVariable::where('service_id', $resource->service_id)->where('key', $variableName)->first();
                        $fqdn = Url::fromString($fqdn);
                        $fqdn = $fqdn->getScheme() . '://' . $fqdn->getHost();
                        if ($generatedEnv) {
                            $generatedEnv->value = $fqdn;
                            $generatedEnv->save();
                        }
                        $variableName = "SERVICE_URL_" . Str::of($resource->name)->upper()->replace('-', '');
                        $generatedEnv = EnvironmentVariable::where('service_id', $resource->service_id)->where('key', $variableName)->first();
                        $url = Url::fromString($fqdn);
                        $url = $url->getHost();
                        if ($generatedEnv) {
                            $url = Str::of($fqdn)->after('://');
                            $generatedEnv->value = $url;
                            $generatedEnv->save();
                        }
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        return handleError($e);
    }
}
