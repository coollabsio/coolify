<?php

namespace App\Models;

use App\Actions\Server\InstallLogDrain;
use App\Actions\Server\InstallNewRelic;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Notifications\Server\Revived;
use App\Notifications\Server\Unreachable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Spatie\SchemalessAttributes\Casts\SchemalessAttributes;
use Spatie\SchemalessAttributes\SchemalessAttributesTrait;
use Illuminate\Support\Str;

class Server extends BaseModel
{
    use SchemalessAttributesTrait;
    public static $batch_counter = 0;

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
        'logdrain_axiom_api_key' => 'encrypted',
        'logdrain_newrelic_license_key' => 'encrypted',
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
    public function skipServer()
    {
        if ($this->ip === '1.2.3.4') {
            ray('skipping 1.2.3.4');
            return true;
        }
        return false;
    }
    public function isServerReady()
    {
        $serverUptimeCheckNumber = $this->unreachable_count;
        $serverUptimeCheckNumberMax = 3;

        $currentTime = now()->timestamp;
        $runtime = 30;

        $isReady = false;
        // Run for 30 seconds max and check every 5 seconds for 3 times
        while ($currentTime + $runtime > now()->timestamp) {
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
                $isReady = false;
                break;
            }
            $result = $this->validateConnection();
            // ray('validateConnection: ' . $result);
            if (!$result) {
                $serverUptimeCheckNumber++;
                $this->update([
                    'unreachable_count' => $serverUptimeCheckNumber,
                ]);
                Sleep::for(5)->seconds();
                return;
            }
            $isReady = true;
            break;
        }
        return $isReady;
    }
    public function getDiskUsage()
    {
        return instant_remote_process(["df /| tail -1 | awk '{ print $5}' | sed 's/%//g'"], $this, false);
    }
    public function definedResources()
    {
        $applications = $this->applications();
        $databases = $this->databases();
        $services = $this->services();
        return $applications->concat($databases)->concat($services->get());
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
    public function dockerComposeBasedApplications()
    {
        return $this->applications()->filter(function ($application) {
            return data_get($application, 'build_pack') === 'dockercompose';
        });
    }
    public function dockerComposeBasedPreviewDeployments()
    {
        return $this->previews()->filter(function ($preview) {
            $applicationId = data_get($preview, 'application_id');
            $application = Application::find($applicationId);
            if (!$application) {
                return false;
            }
            return data_get($application, 'build_pack') === 'dockercompose';
        });
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
    public function isLogDrainEnabled()
    {
        return $this->settings->is_logdrain_newrelic_enabled || $this->settings->is_logdrain_highlight_enabled || $this->settings->is_logdrain_axiom_enabled;
    }
    public function validateOS(): bool | Str
    {
        $os_release = instant_remote_process(['cat /etc/os-release'], $this);
        $datas = collect(explode("\n", $os_release));
        $collectedData = collect([]);
        foreach ($datas as $data) {
            $item = Str::of($data)->trim();
            $collectedData->put($item->before('=')->value(), $item->after('=')->lower()->replace('"', '')->value());
        }
        $ID = data_get($collectedData, 'ID');
        // $ID_LIKE = data_get($collectedData, 'ID_LIKE');
        // $VERSION_ID = data_get($collectedData, 'VERSION_ID');
        $supported = collect(SUPPORTED_OS)->filter(function ($supportedOs) use ($ID) {
            if (str($supportedOs)->contains($ID)) {
                return str($ID);
            }
        });
        if ($supported->count() === 1) {
            ray('supported');
            return str($supported->first());
        } else {
            ray('not supported');
            return false;
        }
    }
    public function validateConnection()
    {
        if ($this->skipServer()) {
            return false;
        }

        $uptime = instant_remote_process(['uptime'], $this, false);
        if (!$uptime) {
            $this->settings()->update([
                'is_reachable' => false,
            ]);
            return false;
        } else {
            $this->settings()->update([
                'is_reachable' => true,
            ]);
            $this->update([
                'unreachable_count' => 0,
            ]);
        }

        if (data_get($this, 'unreachable_notification_sent') === true) {
            $this->team->notify(new Revived($this));
            $this->update(['unreachable_notification_sent' => false]);
        }

        return true;
    }
    public function validateDockerEngine($throwError = false)
    {
        $dockerBinary = instant_remote_process(["command -v docker"], $this, false);
        if (is_null($dockerBinary)) {
            $this->settings->is_usable = false;
            $this->settings->save();
            if ($throwError) {
                throw new \Exception('Server is not usable. Docker Engine is not installed.');
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
        $this->settings->is_reachable = true;
        $this->settings->is_usable = true;
        $this->settings->save();
        return true;
    }
    public function validateCoolifyNetwork()
    {
        return instant_remote_process(["docker network create coolify --attachable >/dev/null 2>&1 || true"], $this, false);
    }
    public function executeRemoteCommand(Collection $commands, ?ApplicationDeploymentQueue $loggingModel = null)
    {
        static::$batch_counter++;
        foreach ($commands as $command) {
            $realCommand = data_get($command, 'command');
            if (is_null($realCommand)) {
                throw new \RuntimeException('Command is not set');
            }
            $hidden = data_get($command, 'hidden', false);
            $ignoreErrors = data_get($command, 'ignoreErrors', false);
            $customOutputType = data_get($command, 'customOutputType');
            $name = data_get($command, 'name');
            $remoteCommand = generateSshCommand($this, $realCommand);

            $process = Process::timeout(3600)->idleTimeout(3600)->start($remoteCommand, function (string $type, string $output) use ($realCommand, $hidden, $customOutputType, $loggingModel, $name) {
                $output = str($output)->trim();
                if ($output->startsWith('╔')) {
                    $output = "\n" . $output;
                }
                $newLogEntry = [
                    'command' => remove_iip($realCommand),
                    'output' => remove_iip($output),
                    'type' => $customOutputType ?? $type === 'err' ? 'stderr' : 'stdout',
                    'timestamp' => Carbon::now('UTC'),
                    'hidden' => $hidden,
                    'batch' => static::$batch_counter,
                ];
                if ($loggingModel) {
                    if (!$loggingModel->logs) {
                        $newLogEntry['order'] = 1;
                    } else {
                        $previousLogs = json_decode($loggingModel->logs, associative: true, flags: JSON_THROW_ON_ERROR);
                        $newLogEntry['order'] = count($previousLogs) + 1;
                    }
                    if ($name) {
                        $newLogEntry['name'] = $name;
                    }

                    $previousLogs[] = $newLogEntry;
                    $loggingModel->logs = json_encode($previousLogs, flags: JSON_THROW_ON_ERROR);
                    $loggingModel->save();
                }
            });
            if ($loggingModel) {
                $loggingModel->update([
                    'current_process_id' => $process->id(),
                ]);
            }
            $processResult = $process->wait();
            if ($processResult->exitCode() !== 0) {
                if (!$ignoreErrors) {
                    if ($loggingModel) {
                        $status = ApplicationDeploymentStatus::FAILED->value;
                        $loggingModel->status = $status;
                        $loggingModel->save();
                    }
                    throw new \RuntimeException($processResult->errorOutput());
                }
            }
        }
    }
    public function stopApplicationRelatedRunningContainers(string $applicationId, string $containerName)
    {
        $containers = getCurrentApplicationContainerStatus($this, $applicationId, 0);
        $containers = $containers->filter(function ($container) use ($containerName) {
            return data_get($container, 'Names') !== $containerName;
        });
        $containers->each(function ($container) {
            $removableContainer = data_get($container, 'Names');
            $this->server->executeRemoteCommand(
                commands: collect([
                    'command' => "docker rm -f $removableContainer >/dev/null 2>&1",
                    'hidden' => true,
                    'ignoreErrors' => true
                ]),
                loggingModel: $this->deploymentQueueEntry
            );
        });
    }
}
