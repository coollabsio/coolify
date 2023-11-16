<?php

namespace App\Models;

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Notifications\Server\Revived;
use App\Notifications\Server\Unreachable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Sleep;
use Spatie\SchemalessAttributes\Casts\SchemalessAttributes;
use Spatie\SchemalessAttributes\SchemalessAttributesTrait;
use Illuminate\Support\Str;

class Server extends BaseModel
{
    use SchemalessAttributesTrait;

    protected static function booted()
    {
        static::saving(function ($server) {
            $payload = [];
            if ($server->user) {
                $payload['user'] = Str::of($server->user)->trim();
            }
            if ($server->ip) {
                $payload['ip'] = Str::of($server->ip)->trim();
            }
            $server->forceFill($payload);
        });

        static::created(function ($server) {
            ServerSetting::create([
                'server_id' => $server->id,
            ]);
            if ($server->id === 0) {
                StandaloneDocker::create([
                    'id' => 0,
                    'name' => 'coolify',
                    'network' => 'coolify',
                    'server_id' => $server->id,
                ]);
            } else {
                StandaloneDocker::create([
                    'name' => 'coolify',
                    'network' => 'coolify',
                    'server_id' => $server->id,
                ]);
            }
        });
        static::deleting(function ($server) {
            $server->destinations()->each(function ($destination) {
                $destination->delete();
            });
            $server->settings()->delete();
        });
    }

    public $casts = [
        'proxy' => SchemalessAttributes::class,
    ];
    protected $schemalessAttributes = [
        'proxy',
    ];
    protected $guarded = [];

    static public function isReachable()
    {
        return Server::ownedByCurrentTeam()->whereRelation('settings', 'is_reachable', true);
    }

    static public function ownedByCurrentTeam(array $select = ['*'])
    {
        $teamId = currentTeam()->id;
        $selectArray = collect($select)->concat(['id']);
        return Server::whereTeamId($teamId)->with('settings')->select($selectArray->all())->orderBy('name');
    }

    static public function isUsable()
    {
        return Server::ownedByCurrentTeam()->whereRelation('settings', 'is_reachable', true)->whereRelation('settings', 'is_usable', true);
    }

    static public function destinationsByServer(string $server_id)
    {
        $server = Server::ownedByCurrentTeam()->get()->where('id', $server_id)->firstOrFail();
        $standaloneDocker = collect($server->standaloneDockers->all());
        $swarmDocker = collect($server->swarmDockers->all());
        return $standaloneDocker->concat($swarmDocker);
    }
    public function settings()
    {
        return $this->hasOne(ServerSetting::class);
    }

    public function proxyType()
    {
        $proxyType = $this->proxy->get('type');
        if ($proxyType === ProxyTypes::NONE->value) {
            return $proxyType;
        }
        if (is_null($proxyType)) {
            $this->proxy->type = ProxyTypes::TRAEFIK_V2->value;
            $this->proxy->status = ProxyStatus::EXITED->value;
            $this->save();
        }
        return $this->proxy->get('type');
    }
    public function scopeWithProxy(): Builder
    {
        return $this->proxy->modelScope();
    }

    public function isLocalhost()
    {
        return $this->ip === 'host.docker.internal' || $this->id === 0;
    }
    public function checkServerRediness()
    {
        $serverUptimeCheckNumber = $this->unreachable_count;
        $serverUptimeCheckNumberMax = 5;

        $currentTime = now()->timestamp;
        $runtime5Minutes = 1 * 60;
        // Run for 1 minutes max and check every 5 seconds
        while ($currentTime + $runtime5Minutes > now()->timestamp) {
            if ($serverUptimeCheckNumber >= $serverUptimeCheckNumberMax) {
                if ($this->unreachable_notification_sent === false) {
                    ray('Server unreachable, sending notification...');
                    $this->team->notify(new Unreachable($this));
                    $this->update(['unreachable_notification_sent' => true]);
                }
                $this->settings()->update([
                    'is_reachable' => false,
                ]);
                $this->update([
                    'unreachable_count' => 0,
                ]);
                foreach ($this->applications() as $application) {
                    $application->update(['status' => 'exited']);
                }
                foreach ($this->databases() as $database) {
                    $database->update(['status' => 'exited']);
                }
                foreach ($this->services() as $service) {
                    $apps = $service->applications()->get();
                    $dbs = $service->databases()->get();
                    foreach ($apps as $app) {
                        $app->update(['status' => 'exited']);
                    }
                    foreach ($dbs as $db) {
                        $db->update(['status' => 'exited']);
                    }
                }
                throw new \Exception('Server is not reachable.');
            }
            $result = $this->validateConnection();
            ray('validateConnection: ' . $result);
            if (!$result) {
                $serverUptimeCheckNumber++;
                $this->update([
                    'unreachable_count' => $serverUptimeCheckNumber,
                ]);
                Sleep::for(5)->seconds();
                return;
            }
            $this->update([
                'unreachable_count' => 0,
            ]);
            if (data_get($this, 'unreachable_notification_sent') === true) {
                ray('Server is reachable again, sending notification...');
                $this->team->notify(new Revived($this));
                $this->update(['unreachable_notification_sent' => false]);
            }
            if (
                data_get($this, 'settings.is_reachable') === false ||
                data_get($this, 'settings.is_usable') === false
            ) {
                $this->settings()->update([
                    'is_reachable' => true,
                    'is_usable' => true
                ]);
            }
            break;
        }
    }
    public function getDiskUsage()
    {
        return instant_remote_process(["df /| tail -1 | awk '{ print $5}' | sed 's/%//g'"], $this, false);
    }
    public function hasDefinedResources()
    {
        $applications = $this->applications()->count() > 0;
        $databases = $this->databases()->count() > 0;
        $services = $this->services()->count() > 0;
        if ($applications || $databases || $services) {
            return true;
        }
        return false;
    }

    public function databases()
    {
        return $this->destinations()->map(function ($standaloneDocker) {
            $postgresqls = data_get($standaloneDocker, 'postgresqls', collect([]));
            $redis = data_get($standaloneDocker, 'redis', collect([]));
            $mongodbs = data_get($standaloneDocker, 'mongodbs', collect([]));
            $mysqls = data_get($standaloneDocker, 'mysqls', collect([]));
            $mariadbs = data_get($standaloneDocker, 'mariadbs', collect([]));
            return $postgresqls->concat($redis)->concat($mongodbs)->concat($mysqls)->concat($mariadbs);
        })->flatten();
    }
    public function applications()
    {
        return $this->destinations()->map(function ($standaloneDocker) {
            return $standaloneDocker->applications;
        })->flatten();
    }
    public function services()
    {
        return $this->hasMany(Service::class);
    }
    public function getIp(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (isDev()) {
                    return '127.0.0.1';
                }
                if ($this->isLocalhost()) {
                    return base_ip();
                }
                return $this->ip;
            }
        );
    }
    public function previews()
    {
        return $this->destinations()->map(function ($standaloneDocker) {
            return $standaloneDocker->applications->map(function ($application) {
                return $application->previews;
            })->flatten();
        })->flatten();
    }

    public function destinations()
    {
        $standalone_docker = $this->hasMany(StandaloneDocker::class)->get();
        $swarm_docker = $this->hasMany(SwarmDocker::class)->get();
        return $standalone_docker->concat($swarm_docker);
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

    public function muxFilename()
    {
        return "{$this->ip}_{$this->port}_{$this->user}";
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
        // foreach ($this->applications() as $application) {
        //     if (data_get($application, 'fqdn')) {
        //         $shouldRun = true;
        //         break;
        //     }
        // }
        // ray($this->services()->get());

        // if ($this->id === 0) {
        //     $settings = InstanceSettings::get();
        //     if (data_get($settings, 'fqdn')) {
        //         $shouldRun = true;
        //     }
        // }
        return true;
    }
    public function isFunctional()
    {
        return $this->settings->is_reachable && $this->settings->is_usable;
    }
    public function validateConnection()
    {
        $uptime = instant_remote_process(['uptime'], $this, false);
        if (!$uptime) {
            $this->settings->is_reachable = false;
            $this->settings->save();
            return false;
        }
        $this->settings->is_reachable = true;
        $this->settings->save();
        return true;
    }
    public function validateDockerEngine($throwError = false)
    {
        $dockerBinary = instant_remote_process(["command -v docker"], $this, false);
        if (is_null($dockerBinary)) {
            $this->settings->is_usable = false;
            $this->settings->save();
            if ($throwError) {
                throw new \Exception('Server is not usable.');
            }
            return false;
        }
        $this->settings->is_usable = true;
        $this->settings->save();
        $this->validateCoolifyNetwork();
        return true;
    }
    public function validateDockerEngineVersion()
    {
        $dockerVersion = instant_remote_process(["docker version|head -2|grep -i version| awk '{print $2}'"], $this, false);
        $dockerVersion = checkMinimumDockerEngineVersion($dockerVersion);
        if (is_null($dockerVersion)) {
            $this->settings->is_usable = false;
            $this->settings->save();
            return false;
        }
        $this->settings->is_usable = true;
        $this->settings->save();
        return true;
    }
    public function validateCoolifyNetwork()
    {
        return instant_remote_process(["docker network create coolify --attachable >/dev/null 2>&1 || true"], $this, false);
    }
}
