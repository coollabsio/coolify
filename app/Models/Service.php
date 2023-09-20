<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Yaml;
use Illuminate\Support\Str;

class Service extends BaseModel
{
    use HasFactory;
    protected $guarded = [];
    public function destination()
    {
        return $this->morphTo();
    }
    public function persistentStorages()
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }
    public function portsMappingsArray(): Attribute
    {
        return Attribute::make(
            get: fn () => is_null($this->ports_mappings)
                ? []
                : explode(',', $this->ports_mappings),

        );
    }
    public function portsExposesArray(): Attribute
    {
        return Attribute::make(
            get: fn () => is_null($this->ports_exposes)
                ? []
                : explode(',', $this->ports_exposes)
        );
    }
    public function applications()
    {
        return $this->hasMany(ServiceApplication::class);
    }
    public function databases()
    {
        return $this->hasMany(ServiceDatabase::class);
    }
    public function environment_variables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class)->orderBy('key', 'asc');
    }
    public function parse(bool $saveIt = false): Collection
    {
        if ($this->docker_compose_raw) {
            ray()->clearAll();
            $yaml = Yaml::parse($this->docker_compose_raw);

            $composeVolumes = collect(data_get($yaml, 'volumes', []));
            $composeNetworks = collect(data_get($yaml, 'networks', []));
            $services = data_get($yaml, 'services');
            $definedNetwork = data_get($this, 'destination.network');

            $volumes = collect([]);
            $envs = collect([]);
            $ports = collect([]);

            $services = collect($services)->map(function ($service, $serviceName) use ($composeVolumes, $composeNetworks, $definedNetwork, $envs, $volumes, $ports, $saveIt) {
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
                if ($saveIt) {
                    if ($isDatabase) {
                        $savedService = ServiceDatabase::create([
                            'name' => $serviceName,
                            'service_id' => $this->id
                        ]);
                    } else {
                        $savedService = ServiceApplication::create([
                            'name' => $serviceName,
                            'service_id' => $this->id
                        ]);
                    }
                }
                // Collect ports
                $servicePorts = collect(data_get($service, 'ports', []));
                $ports->put($serviceName, $servicePorts);
                if ($saveIt) {
                    $savedService->ports_exposes = $servicePorts->implode(',');
                    $savedService->save();
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
                            if (!Str::startsWith($volumeName, '/')) {
                                $composeVolumes->put($volumeName, null);
                            }
                            $volumes->put($volumeName, $volumePath);
                            if ($saveIt) {
                                LocalPersistentVolume::create([
                                    'name' => $volumeName,
                                    'mount_path' => $volumePath,
                                    'resource_id' => $savedService->id,
                                    'resource_type' => get_class($savedService)
                                ]);
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
                            $composeNetworks->put($networkName, null);
                        }
                    }
                }
                // Add Coolify specific networks
                $definedNetworkExists = $composeNetworks->contains(function ($value, $_) use ($definedNetwork) {
                    return $value == $definedNetwork;
                });
                if (!$definedNetworkExists) {
                    $composeNetworks->put($definedNetwork, [
                        'external' => true
                    ]);
                }

                // Get variables from the service that does not start with SERVICE_*
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
                                    if ($saveIt) {
                                        EnvironmentVariable::create([
                                            'key' => $nakedName->value(),
                                            'value' => $nakedValue->value(),
                                            'is_build_time' => false,
                                            'service_id' => $this->id,
                                            'is_preview' => false,
                                        ]);
                                    }
                                }
                            } else {
                                if (!$envs->has($nakedName->value())) {
                                    $envs->put($nakedName->value(), null);
                                    if ($saveIt) {
                                        EnvironmentVariable::create([
                                            'key' => $nakedName->value(),
                                            'value' => null,
                                            'is_build_time' => false,
                                            'service_id' => $this->id,
                                            'is_preview' => false,
                                        ]);
                                    }
                                }
                            }
                        }
                    } else {
                        $value = Str::of(replaceVariables(Str::of($value)));
                        $generatedValue = null;
                        if ($value->startsWith('SERVICE_USER')) {
                            $generatedValue = Str::random(10);
                            if ($saveIt) {
                                if (!$envs->has($value->value())) {
                                    $envs->put($value->value(), $generatedValue);
                                    EnvironmentVariable::create([
                                        'key' => $value->value(),
                                        'value' => $generatedValue,
                                        'is_build_time' => false,
                                        'service_id' => $this->id,
                                        'is_preview' => false,
                                    ]);
                                }
                            }
                        } else if ($value->startsWith('SERVICE_PASSWORD')) {
                            $generatedValue = Str::password(symbols: false);
                            if ($saveIt) {
                                if (!$envs->has($value->value())) {
                                    $envs->put($value->value(), $generatedValue);
                                    EnvironmentVariable::create([
                                        'key' => $value->value(),
                                        'value' => $generatedValue,
                                        'is_build_time' => false,
                                        'service_id' => $this->id,
                                        'is_preview' => false,
                                    ]);
                                }
                            }
                        }
                    }
                }

                data_forget($service, 'is_database');
                data_forget($service, 'documentation');
                return $service;
            });
            data_set($services, 'volumes', $composeVolumes->toArray());
            data_set($services, 'networks', $composeNetworks->toArray());
            $this->docker_compose = Yaml::parse($services);
            // $compose = Str::of(Yaml::dump($services, 10, 2));
            // TODO: Replace SERVICE_FQDN_* with the actual FQDN
            // TODO: Replace SERVICE_URL_*

            $shouldBeDefined = collect([
                'envs' => $envs,
                'volumes' => $volumes,
                'ports' => $ports
            ]);
            $parsedCompose = collect([
                'dockerCompose' => $services,
                'shouldBeDefined' => $shouldBeDefined
            ]);
            return $parsedCompose;
        } else {
            return collect([]);
        }
    }
}
