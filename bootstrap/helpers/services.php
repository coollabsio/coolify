<?php

use App\Models\Application;
use App\Models\EnvironmentVariable;
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
        if ($resource->fqdn) {
            $resourceFqdns = str($resource->fqdn)->explode(',');
            if ($resourceFqdns->count() === 1) {
                $resourceFqdns = $resourceFqdns->first();
                $variableName = 'SERVICE_FQDN_'.str($resource->name)->upper()->replace('-', '');
                $generatedEnv = EnvironmentVariable::where('resourceable_type', Service::class)
                    ->where('resourceable_id', $resource->service_id)
                    ->where('key', $variableName)
                    ->first();
                $fqdn = Url::fromString($resourceFqdns);
                $port = $fqdn->getPort();
                $path = $fqdn->getPath();
                $fqdn = $fqdn->getScheme().'://'.$fqdn->getHost();
                if ($generatedEnv) {
                    if ($path === '/') {
                        $generatedEnv->value = $fqdn;
                    } else {
                        $generatedEnv->value = $fqdn.$path;
                    }
                    $generatedEnv->save();
                }
                if ($port) {
                    $variableName = $variableName."_$port";
                    $generatedEnv = EnvironmentVariable::where('resourceable_type', Service::class)
                        ->where('resourceable_id', $resource->service_id)
                        ->where('key', $variableName)
                        ->first();
                    if ($generatedEnv) {
                        if ($path === '/') {
                            $generatedEnv->value = $fqdn;
                        } else {
                            $generatedEnv->value = $fqdn.$path;
                        }
                        $generatedEnv->save();
                    }
                }
                $variableName = 'SERVICE_URL_'.str($resource->name)->upper()->replace('-', '');
                $generatedEnv = EnvironmentVariable::where('resourceable_type', Service::class)
                    ->where('resourceable_id', $resource->service_id)
                    ->where('key', $variableName)
                    ->first();
                $url = Url::fromString($fqdn);
                $port = $url->getPort();
                $path = $url->getPath();
                $url = $url->getHost();
                if ($generatedEnv) {
                    $url = str($fqdn)->after('://');
                    if ($path === '/') {
                        $generatedEnv->value = $url;
                    } else {
                        $generatedEnv->value = $url.$path;
                    }
                    $generatedEnv->save();
                }
                if ($port) {
                    $variableName = $variableName."_$port";
                    $generatedEnv = EnvironmentVariable::where('resourceable_type', Service::class)
                        ->where('resourceable_id', $resource->service_id)
                        ->where('key', $variableName)
                        ->first();
                    if ($generatedEnv) {
                        if ($path === '/') {
                            $generatedEnv->value = $url;
                        } else {
                            $generatedEnv->value = $url.$path;
                        }
                        $generatedEnv->save();
                    }
                }
            } elseif ($resourceFqdns->count() > 1) {
                foreach ($resourceFqdns as $fqdn) {
                    $host = Url::fromString($fqdn);
                    $port = $host->getPort();
                    $url = $host->getHost();
                    $path = $host->getPath();
                    $host = $host->getScheme().'://'.$host->getHost();
                    if ($port) {
                        $port_envs = EnvironmentVariable::where('resourceable_type', Service::class)
                            ->where('resourceable_id', $resource->service_id)
                            ->where('key', 'like', "SERVICE_FQDN_%_$port")
                            ->get();
                        foreach ($port_envs as $port_env) {
                            $service_fqdn = str($port_env->key)->beforeLast('_')->after('SERVICE_FQDN_');
                            $env = EnvironmentVariable::where('resourceable_type', Service::class)
                                ->where('resourceable_id', $resource->service_id)
                                ->where('key', 'SERVICE_FQDN_'.$service_fqdn)
                                ->first();
                            if ($env) {
                                if ($path === '/') {
                                    $env->value = $host;
                                } else {
                                    $env->value = $host.$path;
                                }
                                $env->save();
                            }
                            if ($path === '/') {
                                $port_env->value = $host;
                            } else {
                                $port_env->value = $host.$path;
                            }
                            $port_env->save();
                        }
                        $port_envs_url = EnvironmentVariable::where('resourceable_type', Service::class)
                            ->where('resourceable_id', $resource->service_id)
                            ->where('key', 'like', "SERVICE_URL_%_$port")
                            ->get();
                        foreach ($port_envs_url as $port_env_url) {
                            $service_url = str($port_env_url->key)->beforeLast('_')->after('SERVICE_URL_');
                            $env = EnvironmentVariable::where('resourceable_type', Service::class)
                                ->where('resourceable_id', $resource->service_id)
                                ->where('key', 'SERVICE_URL_'.$service_url)
                                ->first();
                            if ($env) {
                                if ($path === '/') {
                                    $env->value = $url;
                                } else {
                                    $env->value = $url.$path;
                                }
                                $env->save();
                            }
                            if ($path === '/') {
                                $port_env_url->value = $url;
                            } else {
                                $port_env_url->value = $url.$path;
                            }
                            $port_env_url->save();
                        }
                    } else {
                        $variableName = 'SERVICE_FQDN_'.str($resource->name)->upper()->replace('-', '');
                        $generatedEnv = EnvironmentVariable::where('resourceable_type', Service::class)
                            ->where('resourceable_id', $resource->service_id)
                            ->where('key', $variableName)
                            ->first();
                        $fqdn = Url::fromString($fqdn);
                        $fqdn = $fqdn->getScheme().'://'.$fqdn->getHost().$fqdn->getPath();
                        if ($generatedEnv) {
                            $generatedEnv->value = $fqdn;
                            $generatedEnv->save();
                        }
                        $variableName = 'SERVICE_URL_'.str($resource->name)->upper()->replace('-', '');
                        $generatedEnv = EnvironmentVariable::where('resourceable_type', Service::class)
                            ->where('resourceable_id', $resource->service_id)
                            ->where('key', $variableName)
                            ->first();
                        $url = Url::fromString($fqdn);
                        $url = $url->getHost().$url->getPath();
                        if ($generatedEnv) {
                            $url = str($fqdn)->after('://');
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
function serviceKeys()
{
    return get_service_templates()->keys();
}
