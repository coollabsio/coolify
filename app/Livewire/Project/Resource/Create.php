<?php

namespace App\Livewire\Project\Resource;

use App\Models\EnvironmentVariable;
use App\Models\Service;
use App\Models\StandaloneDocker;
use Livewire\Component;

class Create extends Component
{
    public $type;

    public $project;

    public function mount()
    {
        $type = str(request()->query('type'));
        $destination_uuid = request()->query('destination');
        $server_id = request()->query('server_id');

        $project = currentTeam()->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $this->project = $project;
        $environment = $project->load(['environments'])->environments->where('name', request()->route('environment_name'))->first();
        if (! $environment) {
            return redirect()->route('dashboard');
        }
        if (isset($type) && isset($destination_uuid) && isset($server_id)) {
            $services = get_service_templates();

            if (in_array($type, DATABASE_TYPES)) {
                if ($type->value() === 'postgresql') {
                    $database = create_standalone_postgresql($environment->id, $destination_uuid);
                } elseif ($type->value() === 'redis') {
                    $database = create_standalone_redis($environment->id, $destination_uuid);
                } elseif ($type->value() === 'mongodb') {
                    $database = create_standalone_mongodb($environment->id, $destination_uuid);
                } elseif ($type->value() === 'mysql') {
                    $database = create_standalone_mysql($environment->id, $destination_uuid);
                } elseif ($type->value() === 'mariadb') {
                    $database = create_standalone_mariadb($environment->id, $destination_uuid);
                } elseif ($type->value() === 'keydb') {
                    $database = create_standalone_keydb($environment->id, $destination_uuid);
                } elseif ($type->value() === 'dragonfly') {
                    $database = create_standalone_dragonfly($environment->id, $destination_uuid);
                } elseif ($type->value() === 'clickhouse') {
                    $database = create_standalone_clickhouse($environment->id, $destination_uuid);
                }

                return redirect()->route('project.database.configuration', [
                    'project_uuid' => $project->uuid,
                    'environment_name' => $environment->name,
                    'database_uuid' => $database->uuid,
                ]);
            }
            if ($type->startsWith('one-click-service-') && ! is_null((int) $server_id)) {
                $oneClickServiceName = $type->after('one-click-service-')->value();
                $oneClickService = data_get($services, "$oneClickServiceName.compose");
                $oneClickDotEnvs = data_get($services, "$oneClickServiceName.envs", null);
                if ($oneClickDotEnvs) {
                    $oneClickDotEnvs = str(base64_decode($oneClickDotEnvs))->split('/\r\n|\r|\n/')->filter(function ($value) {
                        return ! empty($value);
                    });
                }
                if ($oneClickService) {
                    $destination = StandaloneDocker::whereUuid($destination_uuid)->first();
                    $service_payload = [
                        'name' => "$oneClickServiceName-".str()->random(10),
                        'docker_compose_raw' => base64_decode($oneClickService),
                        'environment_id' => $environment->id,
                        'service_type' => $oneClickServiceName,
                        'server_id' => (int) $server_id,
                        'destination_id' => $destination->id,
                        'destination_type' => $destination->getMorphClass(),
                    ];
                    if ($oneClickServiceName === 'cloudflared') {
                        data_set($service_payload, 'connect_to_docker_network', true);
                    }
                    $service = Service::create($service_payload);
                    $service->name = "$oneClickServiceName-".$service->uuid;
                    $service->save();
                    if ($oneClickDotEnvs?->count() > 0) {
                        $oneClickDotEnvs->each(function ($value) use ($service) {
                            $key = str()->before($value, '=');
                            $value = str(str()->after($value, '='));
                            $generatedValue = $value;
                            if ($value->contains('SERVICE_')) {
                                $command = $value->after('SERVICE_')->beforeLast('_');
                                $generatedValue = generateEnvValue($command->value(), $service);
                            }
                            EnvironmentVariable::create([
                                'key' => $key,
                                'value' => $generatedValue,
                                'service_id' => $service->id,
                                'is_build_time' => false,
                                'is_preview' => false,
                            ]);
                        });
                    }
                    $service->parse(isNew: true);

                    return redirect()->route('project.service.configuration', [
                        'service_uuid' => $service->uuid,
                        'environment_name' => $environment->name,
                        'project_uuid' => $project->uuid,
                    ]);
                }
            }
            $this->type = $type->value();
        }
    }

    public function render()
    {
        return view('livewire.project.resource.create');
    }
}
