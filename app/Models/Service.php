<?php

namespace App\Models;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $description
 * @property string|null $docker_compose_raw
 * @property string $compose_parsing_version
 * @property bool $is_container_label_escape_enabled
 * @property \App\Models\Server|null $server
 * @property \App\Models\Environment|null $environment
 * @property \Illuminate\Database\Eloquent\Collection|\App\Models\ServiceApplication[] $applications
 * @property \Illuminate\Database\Eloquent\Collection|\App\Models\ServiceDatabase[] $databases
 * @property \Illuminate\Database\Eloquent\Model|null $destination
 * @property \Illuminate\Database\Eloquent\Collection|\App\Models\Tag[] $tags
 */
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;

use App\Enums\ProcessStatus;
use App\Services\ContainerStatusAggregator;
use App\Services\ServiceExtraFields;
use App\Traits\ClearsGlobalSearchCache;
use App\Traits\HasSafeStringAttribute;
use App\Traits\HasServiceConfiguration;
use App\Traits\HasServiceStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Spatie\Activitylog\Models\Activity;
use Spatie\Url\Url;
use Visus\Cuid2\Cuid2;
use Symfony\Component\Yaml\Yaml;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

use function blank;
use function collect;
use function config;
use function data_get;
use function ray;
use function route;
use function str;

#[OA\Schema(
    description: 'Service model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer', 'description' => 'The unique identifier of the service. Only used for database identification.'],
        'uuid' => ['type' => 'string', 'description' => 'The unique identifier of the service.'],
        'name' => ['type' => 'string', 'description' => 'The name of the service.'],
        'environment_id' => ['type' => 'integer', 'description' => 'The unique identifier of the environment where the service is attached to.'],
        'server_id' => ['type' => 'integer', 'description' => 'The unique identifier of the server where the service is running.'],
        'description' => ['type' => 'string', 'description' => 'The description of the service.'],
        'docker_compose_raw' => ['type' => 'string', 'description' => 'The raw docker-compose.yml file of the service.'],
        'docker_compose' => ['type' => 'string', 'description' => 'The docker-compose.yml file that is parsed and modified by Coolify.'],
        'destination_type' => ['type' => 'string', 'description' => 'Destination type.'],
        'destination_id' => ['type' => 'integer', 'description' => 'The unique identifier of the destination where the service is running.'],
        'connect_to_docker_network' => ['type' => 'boolean', 'description' => 'The flag to connect the service to the predefined Docker network.'],
        'is_container_label_escape_enabled' => ['type' => 'boolean', 'description' => 'The flag to enable the container label escape.'],
        'is_container_label_readonly_enabled' => ['type' => 'boolean', 'description' => 'The flag to enable the container label readonly.'],
        'config_hash' => ['type' => 'string', 'description' => 'The hash of the service configuration.'],
        'service_type' => ['type' => 'string', 'description' => 'The type of the service.'],
        'created_at' => ['type' => 'string', 'description' => 'The date and time when the service was created.'],
        'updated_at' => ['type' => 'string', 'description' => 'The date and time when the service was last updated.'],
        'deleted_at' => ['type' => 'string', 'description' => 'The date and time when the service was deleted.'],
    ],
)]
class Service extends BaseModel
{
    use ClearsGlobalSearchCache, HasFactory, HasSafeStringAttribute, HasServiceConfiguration, HasServiceStatus, SoftDeletes;

    private static $parserVersion = '5';

    protected $guarded = [];

    protected $appends = ['server_status', 'status'];

    protected static function booted()
    {
        static::creating(function ($service) {
            if (blank($service->name)) {
                $service->name = 'service-' . (new Cuid2);
            }
        });
        static::created(function ($service) {
            $service->compose_parsing_version = self::$parserVersion;
            $service->save();
        });
    }


    protected function serverStatus(): Attribute
    {
        return Attribute::make(
            get: function () {
                return $this->server->isFunctional();
            }
        );
    }





    public function extraFields()
    {
        return (new ServiceExtraFields($this))->get();
    }

    public function saveExtraFields($fields)
    {
        (new ServiceExtraFields($this))->save($fields);
    }

    public function link()
    {
        if (data_get($this, 'environment.project.uuid')) {
            return route('project.service.configuration', [
                'project_uuid' => data_get($this, 'environment.project.uuid'),
                'environment_uuid' => data_get($this, 'environment.uuid'),
                'service_uuid' => data_get($this, 'uuid'),
            ]);
        }

        return null;
    }

    public function taskLink($task_uuid)
    {
        if (data_get($this, 'environment.project.uuid')) {
            $route = route('project.service.scheduled-tasks', [
                'project_uuid' => data_get($this, 'environment.project.uuid'),
                'environment_uuid' => data_get($this, 'environment.uuid'),
                'service_uuid' => data_get($this, 'uuid'),
                'task_uuid' => $task_uuid,
            ]);
            $settings = InstanceSettings::get();
            if (data_get($settings, 'fqdn')) {
                $url = Url::fromString($route);
                $url = $url->withPort(null);
                $fqdn = data_get($settings, 'fqdn');
                $fqdn = str_replace(['http://', 'https://'], '', $fqdn);
                $url = $url->withHost($fqdn);

                return $url->__toString();
            }

            return $route;
        }

        return null;
    }

    public function documentation()
    {
        $services = get_service_templates();
        $service = data_get($services, str($this->name)->beforeLast('-')->value, []);

        return data_get($service, 'documentation', config('constants.urls.docs'));
    }

    /**
     * Get the required port for this service from the template definition.
     */
    public function getRequiredPort(): ?int
    {
        try {
            $services = get_service_templates();
            $serviceName = str($this->name)->beforeLast('-')->value();
            $service = data_get($services, $serviceName, []);
            $port = data_get($service, 'port');

            return $port ? (int) $port : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Check if this service requires a port to function correctly.
     */
    public function requiresPort(): bool
    {
        return $this->getRequiredPort() !== null;
    }

    public function applications()
    {
        return $this->hasMany(ServiceApplication::class);
    }

    public function databases()
    {
        return $this->hasMany(ServiceDatabase::class);
    }

    public function destination()
    {
        return $this->morphTo();
    }

    public function environment()
    {
        return $this->belongsTo(Environment::class);
    }

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    public function byUuid(string $uuid)
    {
        $app = $this->applications()->whereUuid($uuid)->first();
        if ($app) {
            return $app;
        }
        $db = $this->databases()->whereUuid($uuid)->first();
        if ($db) {
            return $db;
        }

        return null;
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

    public function scheduled_tasks(): HasMany
    {
        return $this->hasMany(ScheduledTask::class)->orderBy('name', 'asc');
    }

    public function environment_variables()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable');
    }

    public function workdir()
    {
        return service_configuration_dir() . "/{$this->uuid}";
    }

    public function saveComposeConfigs()
    {
        // Guard against null or empty docker_compose
        if (!$this->docker_compose) {
            return;
        }

        $workdir = $this->workdir();

        instant_remote_process([
            "mkdir -p $workdir",
            "cd $workdir",
        ], $this->server);

        $filename = new Cuid2 . '-docker-compose.yml';
        Storage::disk('local')->put("tmp/{$filename}", $this->docker_compose);
        $path = Storage::path("tmp/{$filename}");
        instant_scp($path, "{$workdir}/docker-compose.yml", $this->server);
        Storage::disk('local')->delete("tmp/{$filename}");

        $commands[] = "cd $workdir";
        $commands[] = 'rm -f .env || true';

        $envs = collect([]);

        // Generate SERVICE_NAME_* environment variables from docker-compose services
        if ($this->docker_compose) {
            try {
                $dockerCompose = Yaml::parse($this->docker_compose);
                $services = data_get($dockerCompose, 'services', []);
                foreach ($services as $serviceName => $_) {
                    $envs->push('SERVICE_NAME_' . str($serviceName)->replace('-', '_')->replace('.', '_')->upper() . '=' . $serviceName);
                }
            } catch (\Exception $e) {
                ray($e->getMessage());
            }
        }

        $envs_from_coolify = $this->environment_variables()->get();
        $sorted = $envs_from_coolify->sortBy(function ($env) {
            if (str($env->key)->startsWith('SERVICE_')) {
                return 1;
            }
            if (str($env->value)->startsWith('$SERVICE_') || str($env->value)->startsWith('${SERVICE_')) {
                return 2;
            }

            return 3;
        });
        foreach ($sorted as $env) {
            $envs->push("{$env->key}={$env->real_value}");
        }
        if ($envs->count() === 0) {
            $commands[] = 'touch .env';
        } else {
            $envs_base64 = base64_encode($envs->implode("\n"));
            $commands[] = "echo '$envs_base64' | base64 -d | tee .env > /dev/null";
        }

        instant_remote_process($commands, $this->server);
    }

    public function parse(bool $isNew = false): Collection
    {
        if ((int) $this->compose_parsing_version >= 3) {
            return serviceParser($this);
        } elseif ($this->docker_compose_raw) {
            return parseDockerComposeFile($this, $isNew);
        } else {
            return collect([]);
        }
    }

    public function networks()
    {
        return getTopLevelNetworks($this);
    }

    protected function isDeployable(): Attribute
    {
        return Attribute::make(
            get: function () {
                $envs = $this->environment_variables()->where('is_required', true)->get();
                foreach ($envs as $env) {
                    if ($env->is_really_required) {
                        return false;
                    }
                }

                return true;
            }
        );
    }
}
