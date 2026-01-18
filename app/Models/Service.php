<?php

namespace App\Models;

use App\Enums\ProcessStatus;
use App\Services\ContainerStatusAggregator;
use App\Traits\ClearsGlobalSearchCache;
use App\Traits\HasSafeStringAttribute;
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
    use ClearsGlobalSearchCache, HasFactory, HasSafeStringAttribute, SoftDeletes;

    private static $parserVersion = '5';

    protected $guarded = [];

    protected $appends = ['server_status', 'status'];

    protected static function booted()
    {
        static::creating(function ($service) {
            if (blank($service->name)) {
                $service->name = 'service-'.(new Cuid2);
            }
        });
        static::created(function ($service) {
            $service->compose_parsing_version = self::$parserVersion;
            $service->save();
        });
    }

    public function isConfigurationChanged(bool $save = false)
    {
        $domains = $this->applications()->get()->pluck('fqdn')->sort()->toArray();
        $domains = implode(',', $domains);

        $applicationImages = $this->applications()->get()->pluck('image')->sort();
        $databaseImages = $this->databases()->get()->pluck('image')->sort();
        $images = $applicationImages->merge($databaseImages);
        $images = implode(',', $images->toArray());

        $applicationStorages = $this->applications()->get()->pluck('persistentStorages')->flatten()->sortBy('id');
        $databaseStorages = $this->databases()->get()->pluck('persistentStorages')->flatten()->sortBy('id');
        $storages = $applicationStorages->merge($databaseStorages)->implode('updated_at');

        $newConfigHash = $images.$domains.$images.$storages;
        $newConfigHash .= json_encode($this->environment_variables()->get('value')->sort());
        $newConfigHash = md5($newConfigHash);
        $oldConfigHash = data_get($this, 'config_hash');
        if ($oldConfigHash === null) {
            if ($save) {
                $this->config_hash = $newConfigHash;
                $this->save();
            }

            return true;
        }
        if ($oldConfigHash === $newConfigHash) {
            return false;
        } else {
            if ($save) {
                $this->config_hash = $newConfigHash;
                $this->save();
            }

            return true;
        }
    }

    protected function serverStatus(): Attribute
    {
        return Attribute::make(
            get: function () {
                return $this->server->isFunctional();
            }
        );
    }

    public function isRunning()
    {
        return (bool) str($this->status)->contains('running');
    }

    public function isExited()
    {
        return (bool) str($this->status)->contains('exited');
    }

    public function isStarting(): bool
    {
        try {
            $activity = Activity::where('properties->type_uuid', $this->uuid)->latest()->first();
            $status = data_get($activity, 'properties.status');

            return $status === ProcessStatus::QUEUED->value || $status === ProcessStatus::IN_PROGRESS->value;
        } catch (\Throwable) {
            return false;
        }
    }

    public function type()
    {
        return 'service';
    }

    public function project()
    {
        return data_get($this, 'environment.project');
    }

    public function team()
    {
        return data_get($this, 'environment.project.team');
    }

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /**
     * Get query builder for services owned by current team.
     * If you need all services without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    public static function ownedByCurrentTeam()
    {
        return Service::whereRelation('environment.project.team', 'id', currentTeam()->id)->orderBy('name');
    }

    /**
     * Get all services owned by current team (cached for request duration).
     */
    public static function ownedByCurrentTeamCached()
    {
        return once(function () {
            return Service::ownedByCurrentTeam()->get();
        });
    }

    public function deleteConfigurations()
    {
        $server = data_get($this, 'destination.server');
        $workdir = $this->workdir();
        if (str($workdir)->endsWith($this->uuid)) {
            instant_remote_process(['rm -rf '.$this->workdir()], $server, false);
        }
    }

    public function deleteConnectedNetworks()
    {
        $server = data_get($this, 'destination.server');
        instant_remote_process(["docker network disconnect {$this->uuid} coolify-proxy"], $server, false);
        instant_remote_process(["docker network rm {$this->uuid}"], $server, false);
    }

    /**
     * Calculate the service's aggregate status from its applications and databases.
     *
     * This method aggregates status from Eloquent model relationships (not Docker containers).
     * It differs from the CalculatesExcludedStatus trait which works with Docker container objects
     * during container inspection. This accessor runs on-demand for UI display and works with
     * already-stored status strings from the database.
     *
     * Status format: "{status}:{health}" or "{status}:{health}:excluded"
     * - Status values: running, exited, degraded, starting, paused, restarting
     * - Health values: healthy, unhealthy, unknown
     * - :excluded suffix: Indicates all containers are excluded from health monitoring
     *
     * @return string The aggregate status in format "status:health" or "status:health:excluded"
     */
    public function getStatusAttribute()
    {
        if ($this->isStarting()) {
            return 'starting:unhealthy';
        }

        $applications = $this->applications;
        $databases = $this->databases;

        [$complexStatus, $complexHealth, $hasNonExcluded] = $this->aggregateResourceStatuses(
            $applications,
            $databases,
            excludedOnly: false
        );

        // If all services are excluded from status checks, calculate status from excluded containers
        // but mark it with :excluded to indicate monitoring is disabled
        if (! $hasNonExcluded && ($complexStatus === null && $complexHealth === null)) {
            [$excludedStatus, $excludedHealth] = $this->aggregateResourceStatuses(
                $applications,
                $databases,
                excludedOnly: true
            );

            // Return status with :excluded suffix to indicate monitoring is disabled
            if ($excludedStatus && $excludedHealth) {
                return "{$excludedStatus}:{$excludedHealth}:excluded";
            }

            // If no status was calculated at all (no containers exist), return unknown
            if ($excludedStatus === null && $excludedHealth === null) {
                return 'unknown:unknown:excluded';
            }

            return 'exited';
        }

        // If health is null/empty, return just the status without trailing colon
        if ($complexHealth === null || $complexHealth === '') {
            return $complexStatus;
        }

        return "{$complexStatus}:{$complexHealth}";
    }

    /**
     * Aggregate status and health from applications and databases.
     *
     * @param  Collection  $applications  Collection of ServiceApplication models
     * @param  Collection  $databases  Collection of ServiceDatabase models
     * @param  bool  $excludedOnly  If true, only consider excluded containers; if false, only consider non-excluded
     * @return array [status, health, hasNonExcluded] where hasNonExcluded indicates if any non-excluded containers exist
     */
    private function aggregateResourceStatuses(
        Collection $applications,
        Collection $databases,
        bool $excludedOnly = false
    ): array {
        $aggregator = new ContainerStatusAggregator;
        $hasNonExcluded = false;

        // Process applications
        foreach ($applications as $app) {
            $statusString = $app->status ?? 'unknown:unknown';
            $isExcluded = str_ends_with($statusString, ':excluded');

            // Skip if filtering doesn't match
            if ($excludedOnly && ! $isExcluded) {
                continue;
            }
            if (! $excludedOnly && $isExcluded) {
                continue;
            }

            // Track if we have any non-excluded containers
            if (! $isExcluded) {
                $hasNonExcluded = true;
            }

            // Remove :excluded suffix for aggregation
            $statusString = str_replace(':excluded', '', $statusString);

            // Parse status:health format
            $parts = explode(':', $statusString);
            $status = $parts[0] ?? 'unknown';
            $health = $parts[1] ?? 'unknown';

            $aggregator->addContainer($status, $health);
        }

        // Process databases
        foreach ($databases as $db) {
            $statusString = $db->status ?? 'unknown:unknown';
            $isExcluded = str_ends_with($statusString, ':excluded');

            // Skip if filtering doesn't match
            if ($excludedOnly && ! $isExcluded) {
                continue;
            }
            if (! $excludedOnly && $isExcluded) {
                continue;
            }

            // Track if we have any non-excluded containers
            if (! $isExcluded) {
                $hasNonExcluded = true;
            }

            // Remove :excluded suffix for aggregation
            $statusString = str_replace(':excluded', '', $statusString);

            // Parse status:health format
            $parts = explode(':', $statusString);
            $status = $parts[0] ?? 'unknown';
            $health = $parts[1] ?? 'unknown';

            $aggregator->addContainer($status, $health);
        }

        $result = $aggregator->getAggregatedStatus();

        return [
            $result['status'] ?? null,
            $result['health'] ?? null,
            $hasNonExcluded,
        ];
    }

    public function link()
    {
        if (data_get($this, 'environment.project.uuid')) {
            return route('project.service.configuration', [
                'project_uuid' => data_get($this, 'environment.project.uuid'),
                'environment_name' => data_get($this, 'environment.name'),
                'service_uuid' => data_get($this, 'uuid'),
            ]);
        }

        return null;
    }

    public function documentation()
    {
        $services = get_service_templates();
        $service = data_get($services, str($this->name)->beforeLast('-')->value, []);

        return data_get($service, 'documentation', false);
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
        return service_configuration_dir()."/{$this->uuid}";
    }

    public function saveComposeConfigs()
    {
        // Guard against null or empty docker_compose
        if (! $this->docker_compose) {
            return;
        }

        $workdir = $this->workdir();

        instant_remote_process([
            "mkdir -p $workdir",
            "cd $workdir",
        ], $this->server);

        $filename = new Cuid2.'-docker-compose.yml';
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
                $dockerCompose = \Symfony\Component\Yaml\Yaml::parse($this->docker_compose);
                $services = data_get($dockerCompose, 'services', []);
                foreach ($services as $serviceName => $_) {
                    $envs->push('SERVICE_NAME_'.str($serviceName)->replace('-', '_')->replace('.', '_')->upper().'='.$serviceName);
                }
            } catch (\Exception $e) {
                ray($e->getMessage());
            }
        }

        // FIX for #7655: Only include Service-level environment variables in the shared .env file
        // Container-specific variables (ServiceApplication/ServiceDatabase) should NOT be here
        // as they are already injected into their respective containers via docker-compose.yml
        
        // Get IDs of all container-specific environment variables to exclude them
        $containerSpecificEnvIds = collect([]);
        
        // Collect env var IDs from all ServiceApplications
        foreach ($this->applications as $app) {
            $containerSpecificEnvIds = $containerSpecificEnvIds->merge(
                $app->environment_variables()->pluck('id')
            );
        }
        
        // Collect env var IDs from all ServiceDatabases
        foreach ($this->databases as $db) {
            $containerSpecificEnvIds = $containerSpecificEnvIds->merge(
                $db->environment_variables()->pluck('id')
            );
        }

        // Only get Service-level environment variables (exclude container-specific ones)
        $envs_from_coolify = $this->environment_variables()
            ->whereNotIn('id', $containerSpecificEnvIds->toArray())
            ->get();
            
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
}
