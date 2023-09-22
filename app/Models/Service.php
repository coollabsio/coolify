<?php

namespace App\Models;

use App\Enums\ProxyTypes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Yaml;
use Illuminate\Support\Str;

class Service extends BaseModel
{
    use HasFactory;
    protected $guarded = [];

    protected static function booted()
    {
        static::deleted(function ($service) {
            $storagesToDelete = collect([]);
            foreach($service->applications()->get() as $application) {
                $storages = $application->persistentStorages()->get();
                foreach ($storages as $storage) {
                    $storagesToDelete->push($storage);
                }
                $application->persistentStorages()->delete();
            }
            foreach($service->databases()->get() as $database) {
                $storages = $database->persistentStorages()->get();
                foreach ($storages as $storage) {
                    $storagesToDelete->push($storage);
                }
                $database->persistentStorages()->delete();
            }
            $service->environment_variables()->delete();
            $service->applications()->delete();
            $service->databases()->delete();
            if ($storagesToDelete->count() > 0) {
                $storagesToDelete->each(function ($storage) use ($service) {
                    instant_remote_process(["docker volume rm -f $storage->name"], $service->server, false);
                });
            }
        });
    }
    public function type()
    {
        return 'service';
    }

    public function applications()
    {
        return $this->hasMany(ServiceApplication::class);
    }
    public function databases()
    {
        return $this->hasMany(ServiceDatabase::class);
    }
    public function environment()
    {
        return $this->belongsTo(Environment::class);
    }
    public function server()
    {
        return $this->belongsTo(Server::class);
    }
    public function byName(string $name)
    {
        $app = $this->applications()->whereName($name)->first();
        if ($app) {
            return $app;
        }
        $db = $this->databases()->whereName($name)->first();
        if ($db) {
            return $db;
        }
        return null;
    }
    public function environment_variables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class)->orderBy('key', 'asc');
    }
    public function parse(bool $isNew = false): Collection
    {
        // ray()->clearAll();
        if ($this->docker_compose_raw) {
            $yaml = Yaml::parse($this->docker_compose_raw);

            $composeVolumes = collect(data_get($yaml, 'volumes', []));
            $composeNetworks = collect(data_get($yaml, 'networks', []));
            $dockerComposeVersion = data_get($yaml, 'version') ?? '3.8';
            $services = data_get($yaml, 'services');
            $definedNetwork = $this->uuid;

            $volumes = collect([]);
            $envs = collect([]);
            $ports = collect([]);

            $services = collect($services)->map(function ($service, $serviceName) use ($composeVolumes, $composeNetworks, $definedNetwork, $envs, $volumes, $ports, $isNew) {
                $container_name = "$serviceName-{$this->uuid}";
                $isDatabase = false;
                // Decide if the service is a database
                $image = data_get($service, 'image');
                if ($image) {
                    $imageName = Str::of($image)->before(':');
                    if (collect(DATABASE_DOCKER_IMAGES)->contains($imageName)) {
                        $isDatabase = true;
                        data_set($service, 'is_database', true);
                    }
                }
                if ($isNew) {
                    if ($isDatabase) {
                        $savedService = ServiceDatabase::create([
                            'name' => $serviceName,
                            'service_id' => $this->id
                        ]);
                    } else {
                        $defaultUsableFqdn = "http://$serviceName-{$this->uuid}.{$this->server->ip}.sslip.io";
                        if (isDev()) {
                            $defaultUsableFqdn = "http://$serviceName-{$this->uuid}.127.0.0.1.sslip.io";
                        }
                        $savedService = ServiceApplication::create([
                            'name' => $serviceName,
                            'fqdn' => $defaultUsableFqdn,
                            'service_id' => $this->id
                        ]);
                    }
                } else {
                    if ($isDatabase) {
                        $savedService = $this->databases()->whereName($serviceName)->first();
                    } else {
                        $savedService = $this->applications()->whereName($serviceName)->first();
                    }
                }
                $fqdn = data_get($savedService, 'fqdn');
                // Collect ports
                $servicePorts = collect(data_get($service, 'ports', []));
                $ports->put($serviceName, $servicePorts);
                if ($isNew) {
                    $ports = collect([]);
                    if ($servicePorts->count() > 0) {
                        foreach ($servicePorts as $sport) {
                            if (is_string($sport)) {
                                $ports->push($sport);
                            }
                            if (is_array($sport)) {
                                $target = data_get($sport, 'target');
                                $published = data_get($sport, 'published');
                                $ports->push("$target:$published");
                            }
                        }
                    }
                    // $savedService->ports_exposes = $ports->implode(',');
                    // $savedService->save();
                }
                // Collect volumes
                $serviceVolumes = collect(data_get($service, 'volumes', []));
                if ($serviceVolumes->count() > 0) {
                    foreach ($serviceVolumes as $volume) {
                        if (is_string($volume)) {
                            $volumeName = Str::before($volume, ':');
                            $volumePath = Str::after($volume, ':');
                        }
                        if (is_array($volume)) {
                            $volumeName = data_get($volume, 'source');
                            $volumePath = data_get($volume, 'target');
                        }

                        $volumeExists = $serviceVolumes->contains(function ($_, $key) use ($volumeName) {
                            return $key == $volumeName;
                        });
                        if (!$volumeExists) {
                            if (Str::startsWith($volumeName, '/')) {
                                $volumes->put($volumeName, $volumePath);
                                LocalPersistentVolume::updateOrCreate(
                                    [
                                        'mount_path' => $volumePath,
                                        'resource_id' => $savedService->id,
                                        'resource_type' => get_class($savedService)
                                    ],
                                    [
                                        'name' => Str::slug($volumeName, '-'),
                                        'mount_path' => $volumePath,
                                        'host_path' => $volumeName,
                                        'resource_id' => $savedService->id,
                                        'resource_type' => get_class($savedService)
                                    ]
                                );
                            } else {
                                $composeVolumes->put($volumeName, null);
                                LocalPersistentVolume::updateOrCreate(
                                    [
                                        'mount_path' => $volumePath,
                                        'resource_id' => $savedService->id,
                                        'resource_type' => get_class($savedService)
                                    ],
                                    [
                                        'name' => $volumeName,
                                        'mount_path' => $volumePath,
                                        'host_path' => null,
                                        'resource_id' => $savedService->id,
                                        'resource_type' => get_class($savedService)
                                    ]
                                );
                            }
                        }
                    }
                }

                // Collect and add networks
                $serviceNetworks = collect(data_get($service, 'networks', []));
                if ($serviceNetworks->count() > 0) {
                    foreach ($serviceNetworks as $networkName => $networkDetails) {
                        $networkExists = $composeNetworks->contains(function ($value, $key) use ($networkName) {
                            return $value == $networkName || $key == $networkName;
                        });
                        if (!$networkExists) {
                            $composeNetworks->put($networkDetails, null);
                        }
                    }
                }
                // Add Coolify specific networks
                $definedNetworkExists = $composeNetworks->contains(function ($value, $_) use ($definedNetwork) {
                    return $value == $definedNetwork;
                });
                if (!$definedNetworkExists) {
                    $composeNetworks->put($definedNetwork, [
                        'name' => $definedNetwork,
                        'external' => false
                    ]);
                }
                $networks = $serviceNetworks->toArray();
                $networks = array_merge($networks, [$definedNetwork]);
                data_set($service, 'networks', $networks);


                // Get variables from the service
                $serviceVariables = collect(data_get($service, 'environment', []));
                foreach ($serviceVariables as $variable) {
                    $value = Str::after($variable, '=');
                    if (!Str::startsWith($value, '$SERVICE_') && !Str::startsWith($value, '${SERVICE_') && Str::startsWith($value, '$')) {
                        $value = Str::of(replaceVariables(Str::of($value)));
                        if ($value->contains(':')) {
                            $nakedName = $value->before(':');
                            $nakedValue = $value->after(':');
                        } else if ($value->contains('-')) {
                            $nakedName = $value->before('-');
                            $nakedValue = $value->after('-');
                        } else if ($value->contains('+')) {
                            $nakedName = $value->before('+');
                            $nakedValue = $value->after('+');
                        } else {
                            $nakedName = $value;
                        }
                        if (isset($nakedName)) {
                            if (isset($nakedValue)) {
                                if ($nakedValue->startsWith('-')) {
                                    $nakedValue = Str::of($nakedValue)->after('-');
                                }
                                if ($nakedValue->startsWith('+')) {
                                    $nakedValue = Str::of($nakedValue)->after('+');
                                }
                                if (!$envs->has($nakedName->value())) {
                                    $envs->put($nakedName->value(), $nakedValue->value());
                                    EnvironmentVariable::updateOrCreate([
                                        'key' => $nakedName->value(),
                                        'service_id' => $this->id,
                                    ], [
                                        'value' => $nakedValue->value(),
                                        'is_build_time' => false,
                                        'service_id' => $this->id,
                                        'is_preview' => false,
                                    ]);
                                }
                            } else {
                                if (!$envs->has($nakedName->value())) {
                                    $envs->put($nakedName->value(), null);
                                    $envExists = EnvironmentVariable::where('service_id', $this->id)->where('key', $nakedName->value())->exists();
                                    if (!$envExists) {
                                        EnvironmentVariable::create([
                                            'key' => $nakedName->value(),
                                            'value' => null,
                                            'service_id' => $this->id,
                                            'is_build_time' => false,
                                            'is_preview' => false,
                                        ]);
                                    }
                                }
                            }
                        }
                    } else {
                        $variableName = Str::of(replaceVariables(Str::of($value)));
                        $generatedValue = null;
                        if ($variableName->startsWith('SERVICE_USER')) {
                            $generatedValue = Str::random(10);
                            if (!$envs->has($variableName->value())) {
                                $envs->put($variableName->value(), $generatedValue);
                                EnvironmentVariable::updateOrCreate([
                                    'key' => $variableName->value(),
                                    'service_id' => $this->id,
                                ], [
                                    'value' => $generatedValue,
                                    'is_build_time' => false,
                                    'service_id' => $this->id,
                                    'is_preview' => false,
                                ]);
                            }
                        } else if ($variableName->startsWith('SERVICE_PASSWORD')) {
                            $generatedValue = Str::password(symbols: false);
                            if (!$envs->has($variableName->value())) {
                                $envs->put($variableName->value(), $generatedValue);
                                EnvironmentVariable::updateOrCreate([
                                    'key' => $variableName->value(),
                                    'service_id' => $this->id,
                                ], [
                                    'value' => $generatedValue,
                                    'is_build_time' => false,
                                    'service_id' => $this->id,
                                    'is_preview' => false,
                                ]);
                            }
                        } else if ($variableName->startsWith('SERVICE_FQDN')) {
                            if ($fqdn) {
                                $environments = collect(data_get($service, 'environment'));
                                $environments = $environments->map(function ($envValue) use ($value, $fqdn) {
                                    $envValue = Str::of($envValue)->replace($value, $fqdn);
                                    return $envValue->value();
                                });
                                $service['environment'] = $environments->toArray();
                            }
                        } else if ($variableName->startsWith('SERVICE_URL')) {
                            if ($fqdn) {
                                $url = Str::of($fqdn)->after('https://')->before('/');
                                $environments = collect(data_get($service, 'environment'));
                                $environments = $environments->map(function ($envValue) use ($value, $url) {
                                    $envValue = Str::of($envValue)->replace($value, $url);
                                    return $envValue->value();
                                });
                                $service['environment'] = $environments->toArray();
                            }
                        }
                    }
                }
                if ($this->server->proxyType() === ProxyTypes::TRAEFIK_V2->value) {
                    $labels = collect(data_get($service, 'labels', []));
                    $labels = collect([]);
                    $labels = $labels->merge(defaultLabels($this->id, $container_name, type: 'service'));
                    if (!$isDatabase) {
                        if ($fqdn) {
                            $labels = $labels->merge(fqdnLabelsForTraefik($fqdn, $container_name, true));
                        }
                    }
                    data_set($service, 'labels', $labels->toArray());
                }
                data_forget($service, 'is_database');
                data_set($service, 'restart', RESTART_MODE);
                data_set($service, 'container_name', $container_name);
                data_forget($service, 'documentation');
                return $service;
            });
            $finalServices = [
                'version' => $dockerComposeVersion,
                'services' => $services->toArray(),
                'volumes' => $composeVolumes->toArray(),
                'networks' => $composeNetworks->toArray(),
            ];
            $this->docker_compose = Yaml::dump($finalServices, 10, 2);
            $this->save();
            $shouldBeDefined = collect([
                'envs' => $envs,
                'volumes' => $volumes,
                'ports' => $ports
            ]);
            $parsedCompose = collect([
                'dockerCompose' => $finalServices,
                'shouldBeDefined' => $shouldBeDefined
            ]);
            return $parsedCompose;
        } else {
            return collect([]);
        }
    }
}
