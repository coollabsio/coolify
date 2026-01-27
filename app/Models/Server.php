<?php

namespace App\Models;

use App\Actions\Server\InstallDocker;
use App\Actions\Server\StartProxy;
use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Jobs\CheckLogDrainContainerJob;
use App\Jobs\ContainerStatusJob;
use App\Jobs\DockerCleanupJob;
use App\Jobs\ServerStatusJob;
use App\Models\Traits\HasUuid;
use App\Notifications\Server\Revived;
use App\Notifications\Server\Unreachable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use Spatie\SchemalessAttributes\Casts\SchemalessAttributes;
use Spatie\SchemalessAttributes\SchemalessAttributesTrait;

class Server extends Model
{
    use HasUuid, Notifiable, SchemalessAttributesTrait, SoftDeletes;

    protected $guarded = [];

    protected $schemalessAttributes = [
        'extra_attributes',
    ];

    protected $casts = [
        'extra_attributes' => SchemalessAttributes::class,
        'proxy' => SchemalessAttributes::class,
        'log_drain' => SchemalessAttributes::class,
    ];

    protected static function booted()
    {
        static::created(function ($server) {
            ServerStatusJob::dispatch($server);
        });
        static::deleting(function ($server) {
            $server->destinations()->each(function ($destination) {
                $destination->delete();
            });
        });
    }

    public function settings()
    {
        return $this->hasOne(InstanceSettings::class);
    }

    public function additionalDestinations()
    {
        return $this->morphMany(LocalFileVolume::class, 'resource');
    }

    public function destinations()
    {
        return $this->hasMany(StandaloneDocker::class);
    }

    public function standaloneDockers()
    {
        return $this->hasMany(StandaloneDocker::class);
    }

    public function swarmDockers()
    {
        return $this->hasMany(SwarmDocker::class);
    }

    public function privateKey()
    {
        return $this->belongsTo(PrivateKey::class);
    }

    public function muxFilename(): Attribute
    {
        return Attribute::make(
            get: function () {
                // Use server UUID and private key ID to create unique mux filename
                // This prevents SSH key confusion between servers
                $keyId = $this->private_key_id ?? 'default';
                return "/var/www/html/storage/app/ssh/mux/{$this->uuid}_{$keyId}";
            }
        );
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function scopeWithProxy(): Builder
    {
        return $this->where('proxy->status', ProxyStatus::EXITED->value);
    }

    public function applications()
    {
        return $this->hasManyThrough(Application::class, StandaloneDocker::class, 'server_id', 'destination_id');
    }

    public function dockerComposeBasedApplications()
    {
        return $this->applications()->where('build_pack', 'dockercompose');
    }

    public function buildPacks()
    {
        return $this->applications()->select('build_pack')->distinct()->get();
    }

    public function services(): HasManyThrough
    {
        return $this->hasManyThrough(Service::class, StandaloneDocker::class, 'server_id', 'destination_id');
    }

    public function databases(): HasManyThrough
    {
        return $this->hasManyThrough(StandalonePostgresql::class, StandaloneDocker::class, 'server_id', 'destination_id')
            ->union($this->hasManyThrough(StandaloneMysql::class, StandaloneDocker::class, 'server_id', 'destination_id'))
            ->union($this->hasManyThrough(StandaloneMariadb::class, StandaloneDocker::class, 'server_id', 'destination_id'))
            ->union($this->hasManyThrough(StandaloneMongodb::class, StandaloneDocker::class, 'server_id', 'destination_id'))
            ->union($this->hasManyThrough(StandaloneRedis::class, StandaloneDocker::class, 'server_id', 'destination_id'))
            ->union($this->hasManyThrough(StandaloneKeydb::class, StandaloneDocker::class, 'server_id', 'destination_id'))
            ->union($this->hasManyThrough(StandaloneDragonfly::class, StandaloneDocker::class, 'server_id', 'destination_id'))
            ->union($this->hasManyThrough(StandaloneClickhouse::class, StandaloneDocker::class, 'server_id', 'destination_id'));
    }

    public function proxiedApplications()
    {
        $applications = collect();
        $this->destinations->each(function ($destination) use ($applications) {
            $destination->applications->each(function ($application) use ($applications) {
                if (data_get($application, 'fqdn')) {
                    $applications->push($application);
                }
            });
        });

        return $applications;
    }

    public function hasDefinedResources()
    {
        $applications = $this->applications()->count();
        $services = $this->services()->count();
        $databases = $this->databases()->count();

        return $applications + $services + $databases;
    }

    public function definedResources()
    {
        $applications = $this->applications();
        $services = $this->services();
        $databases = $this->databases();

        return $applications->union($services)->union($databases);
    }

    public function skipServer()
    {
        if ($this->ip === '1.2.3.4') {
            return true;
        }

        return false;
    }

    public function isNonRoot()
    {
        return $this->user !== 'root';
    }

    public function validateOS()
    {
        $os = instant_remote_process(['ls /etc/os-release'], $this);
        if ($os) {
            $os = instant_remote_process(['cat /etc/os-release'], $this);
            $this->extra_attributes->set('os', $os);
            $this->save();
        }
    }

    public function validateConnection()
    {
        try {
            ['uptime' => $uptime] = $this->validateServer();
            $this->extra_attributes->set('last_online_at', now()->toISOString());
            $this->save();

            return true;
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    public function validateServer($install = true)
    {
        // Clear any cached SSH connections for this server to prevent key confusion
        $this->clearSSHConnectionCache();
        
        try {
            $uptime = instant_remote_process(['uptime'], $this, false);
            if (! $uptime) {
                throw new \Exception('Server is not reachable.');
            }
            preg_match('/up\s+(.+),\s+\d+\s+users?,\s+load\s+average/', $uptime, $matches);
            $uptime = $matches[1] ?? 'unknown';
            if ($install) {
                $dockerVersion = instant_remote_process(['docker version'], $this, false);
                if (! $dockerVersion) {
                    $install = new InstallDocker($this);
                    $install->handle();
                } else {
                    // Check if docker is running
                    $dockerStatus = instant_remote_process(['systemctl is-active docker'], $this, false);
                    if (trim($dockerStatus) !== 'active') {
                        instant_remote_process(['systemctl start docker'], $this, false);
                    }
                }
            }

            return [
                'uptime' => $uptime,
            ];
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * Clear SSH connection cache for this server to prevent key confusion
     */
    private function clearSSHConnectionCache()
    {
        try {
            // Clear multiplexed SSH connections
            $muxFile = $this->muxFilename;
            if (file_exists($muxFile)) {
                unlink($muxFile);
            }
            
            // Clear any cached SSH connections in memory
            $cacheKey = "ssh_connection_{$this->uuid}_{$this->private_key_id}";
            Cache::forget($cacheKey);
            
            // Kill any existing SSH control master processes for this server
            $controlPath = escapeshellarg($muxFile);
            Process::run("ssh -O exit -o ControlPath={$controlPath} {$this->user}@{$this->ip} 2>/dev/null || true");
        } catch (\Throwable $e) {
            // Ignore errors during cleanup
            ray($e->getMessage());
        }
    }

    public function isReachable()
    {
        return $this->extra_attributes->get('last_online_at', false);
    }

    public function isNotifications()
    {
        return data_get($this, 'notification.discord.enabled') || data_get($this, 'notification.telegram.enabled') || data_get($this, 'notification.resend.enabled');
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function isProxyShouldRun()
    {
        if ($this->proxyType() === ProxyTypes::NONE->value) {
            return false;
        }
        $applications = $this->hasProxyApplication();
        $services = $this->hasProxyService();

        return $applications || $services;
    }

    public function hasProxyApplication()
    {
        $applications = $this->applications();
        $applications = $applications->get();
        $applications = $applications->filter(function ($application) {
            return data_get($application, 'fqdn');
        });
        if ($applications->count() > 0) {
            return true;
        }

        return false;
    }

    public function hasProxyService()
    {
        $services = $this->services->filter(function ($service) {
            return $service->applications()->whereNotNull('fqdn')->count() > 0;
        });
        if ($services->count() > 0) {
            return true;
        }

        return false;
    }

    public function proxyPath()
    {
        $base_path = config('coolify.base_config_path').'/proxy';
        $proxy_type = $this->proxyType();
        if ($proxy_type === ProxyTypes::NONE->value) {
            return null;
        }

        return "{$base_path}/{$proxy_type}";
    }

    public function proxyType()
    {
        if (data_get($this, 'proxy.type')) {
            return data_get($this, 'proxy.type');
        }
        if (data_get($this->extra_attributes, 'proxy_type')) {
            return data_get($this->extra_attributes, 'proxy_type');
        }

        return ProxyTypes::TRAEFIK_V2->value;
    }

    public function isSwarm()
    {
        return data_get($this->extra_attributes, 'is_swarm_manager', false) || data_get($this->extra_attributes, 'is_swarm_worker', false);
    }

    public function isSwarmManager()
    {
        return data_get($this->extra_attributes, 'is_swarm_manager', false);
    }

    public function isSwarmWorker()
    {
        return data_get($this->extra_attributes, 'is_swarm_worker', false);
    }

    public function validateDockerSwarmConnection()
    {
        try {
            instant_remote_process(['docker info'], $this, false);
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    public function validateDockerEngine()
    {
        $dockerBinary = instant_remote_process(['which docker'], $this, false);
        if (is_null($dockerBinary)) {
            throw new \Exception('Docker is not installed.');
        }
        $dockerVersion = instant_remote_process(['docker version'], $this, false);
        if (is_null($dockerVersion)) {
            throw new \Exception('Docker is not running.');
        }
        $dockerStatus = instant_remote_process(['systemctl is-active docker'], $this, false);
        if (trim($dockerStatus) !== 'active') {
            throw new \Exception('Docker is not running.');
        }

        return true;
    }

    public function installLogDrain()
    {
        return CheckLogDrainContainerJob::dispatch($this);
    }

    public function startLogDrain()
    {
        $type = data_get($this->log_drain, 'type');
        if (! $type || $type === 'none') {
            return;
        }
        if ($type === 'axiom') {
            $endpoint = data_get($this->log_drain, 'axiom_endpoint');
            $token = data_get($this->log_drain, 'axiom_token');
            $dataset = data_get($this->log_drain, 'axiom_dataset');
            if (! $endpoint || ! $token || ! $dataset) {
                throw new \Exception('Axiom log drain is not configured properly');
            }
            $container_name = 'coolify-log-drain';
            $image = 'ghcr.io/coollabsio/coolify-log-drain:latest';
            instant_remote_process(['docker rm -f '.$container_name], $this, false);
            $command = "docker run --restart=unless-stopped -d --name $container_name --log-driver=json-file --log-opt max-size=10m --log-opt max-file=3 -v /var/run/docker.sock:/var/run/docker.sock -e AXIOM_ENDPOINT='$endpoint' -e AXIOM_TOKEN='$token' -e AXIOM_DATASET='$dataset' $image";
            instant_remote_process([$command], $this);
        } elseif ($type === 'highlight') {
            $project_id = data_get($this->log_drain, 'highlight_project_id');
            if (! $project_id) {
                throw new \Exception('Highlight log drain is not configured properly');
            }
            $container_name = 'coolify-log-drain';
            $image = 'ghcr.io/coollabsio/coolify-log-drain:latest';
            instant_remote_process(['docker rm -f '.$container_name], $this, false);
            $command = "docker run --restart=unless-stopped -d --name $container_name --log-driver=json-file --log-opt max-size=10m --log-opt max-file=3 -v /var/run/docker.sock:/var/run/docker.sock -e HIGHLIGHT_PROJECT_ID='$project_id' $image";
            instant_remote_process([$command], $this);
        }
    }

    public function stopLogDrain()
    {
        instant_remote_process(['docker rm -f coolify-log-drain'], $this, false);
    }

    public function restartLogDrain()
    {
        $this->stopLogDrain();
        $this->startLogDrain();
    }
}