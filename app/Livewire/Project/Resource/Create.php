<?php

namespace App\Livewire\Project\Resource;

use App\Actions\Database\ValidatePostgresqlWalGImage;
use App\Models\EnvironmentVariable;
use App\Models\PostgresqlWalBackupConfiguration;
use App\Models\S3Storage;
use App\Models\Service;
use App\Models\StandalonePostgresql;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Create extends Component
{
    use AuthorizesRequests;

    public $type;

    public $project;

    public function mount()
    {
        $this->authorize('createAnyResource');

        $type = str(request()->query('type'));
        $destination_uuid = request()->query('destination');
        $database_image = request()->query('database_image');
        $databaseMode = request()->query('database_mode');
        $s3StorageUuid = request()->query('s3_storage_uuid');

        $project = currentTeam()->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $this->project = $project;
        $environment = $project->load(['environments'])->environments->where('uuid', request()->route('environment_uuid'))->first();
        if (! $environment) {
            return redirect()->route('dashboard');
        }
        if (isset($type) && isset($destination_uuid)) {
            $destination = find_resource_destination_for_current_team($destination_uuid);
            if (! $destination) {
                return redirect()->route('dashboard');
            }
            $services = get_service_templates();

            if (in_array($type, DATABASE_TYPES)) {
                if ($type->value() === 'postgresql') {
                    // PostgreSQL requires database_image to be explicitly set
                    // If not provided, fall through to Select component for version selection
                    if (! $database_image) {
                        $this->type = $type->value();

                        return;
                    }
                    if ($databaseMode !== null && ! in_array($databaseMode, ['regular', 'pitr'], true)) {
                        throw ValidationException::withMessages([
                            'database_mode' => 'Select a valid PostgreSQL mode.',
                        ]);
                    }

                    if ($databaseMode === 'pitr') {
                        if (! is_string($database_image)) {
                            throw ValidationException::withMessages([
                                'image' => 'Select a supported PostgreSQL WAL-G image.',
                            ]);
                        }
                        $majorVersion = ValidatePostgresqlWalGImage::run($database_image);
                        $storage = is_string($s3StorageUuid)
                            ? S3Storage::ownedByCurrentTeam(['uuid', 'is_usable'])
                                ->where('uuid', $s3StorageUuid)
                                ->where('is_usable', true)
                                ->first()
                            : null;

                        if (! $storage) {
                            throw ValidationException::withMessages([
                                's3_storage_uuid' => 'Select an available S3 storage owned by the current team.',
                            ]);
                        }

                        $database = DB::transaction(function () use ($environment, $destination, $database_image, $storage, $majorVersion): StandalonePostgresql {
                            $database = create_standalone_postgresql(
                                environmentId: $environment->id,
                                destination: $destination,
                                databaseImage: $database_image,
                            );
                            PostgresqlWalBackupConfiguration::create([
                                'team_id' => currentTeam()->id,
                                'standalone_postgresql_id' => $database->id,
                                's3_storage_id' => $storage->id,
                                'enabled' => true,
                                'postgres_major_version' => $majorVersion,
                                'status' => 'warning',
                                'last_health_message' => 'Waiting for PostgreSQL WAL archiving to be verified.',
                            ]);

                            return $database;
                        });
                    } else {
                        $database = create_standalone_postgresql(
                            environmentId: $environment->id,
                            destination: $destination,
                            databaseImage: $database_image,
                        );
                    }
                } elseif ($type->value() === 'redis') {
                    $database = create_standalone_redis($environment->id, $destination);
                } elseif ($type->value() === 'mongodb') {
                    $database = create_standalone_mongodb($environment->id, $destination);
                } elseif ($type->value() === 'mysql') {
                    $database = create_standalone_mysql($environment->id, $destination);
                } elseif ($type->value() === 'mariadb') {
                    $database = create_standalone_mariadb($environment->id, $destination);
                } elseif ($type->value() === 'keydb') {
                    $database = create_standalone_keydb($environment->id, $destination);
                } elseif ($type->value() === 'dragonfly') {
                    $database = create_standalone_dragonfly($environment->id, $destination);
                } elseif ($type->value() === 'clickhouse') {
                    $database = create_standalone_clickhouse($environment->id, $destination);
                }

                return redirect()->route('project.database.configuration', [
                    'project_uuid' => $project->uuid,
                    'environment_uuid' => $environment->uuid,
                    'database_uuid' => $database->uuid,
                ]);
            }
            if ($type->startsWith('one-click-service-')) {
                $oneClickServiceName = $type->after('one-click-service-')->value();
                $oneClickService = data_get($services, "$oneClickServiceName.compose");
                $oneClickDotEnvs = data_get($services, "$oneClickServiceName.envs", null);
                if ($oneClickDotEnvs) {
                    $oneClickDotEnvs = str(base64_decode($oneClickDotEnvs))->split('/\r\n|\r|\n/')->filter(function ($value) {
                        return ! empty($value);
                    });
                }
                if ($oneClickService) {
                    $service_payload = [
                        'docker_compose_raw' => base64_decode($oneClickService),
                        'environment_id' => $environment->id,
                        'service_type' => $oneClickServiceName,
                        'server_id' => $destination->server_id,
                        'destination_id' => $destination->id,
                        'destination_type' => $destination->getMorphClass(),
                    ];
                    if (in_array($oneClickServiceName, NEEDS_TO_CONNECT_TO_PREDEFINED_NETWORK)) {
                        data_set($service_payload, 'connect_to_docker_network', true);
                    }
                    $service = new Service($service_payload);
                    $service->save();
                    $service->name = "$oneClickServiceName-".$service->uuid;
                    $service->save();
                    if ($oneClickDotEnvs?->count() > 0) {
                        $oneClickDotEnvs->each(function ($value) use ($service) {
                            $key = str()->before($value, '=');
                            $value = str(str()->after($value, '='));
                            if ($value) {
                                EnvironmentVariable::create([
                                    'key' => $key,
                                    'value' => $value,
                                    'resourceable_id' => $service->id,
                                    'resourceable_type' => $service->getMorphClass(),
                                    'is_preview' => false,
                                ]);
                            }
                        });
                    }
                    $service->parse(isNew: true);

                    // Apply service-specific application prerequisites
                    applyServiceApplicationPrerequisites($service);

                    return redirect()->route('project.service.configuration', [
                        'service_uuid' => $service->uuid,
                        'environment_uuid' => $environment->uuid,
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
