<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Support\Str;

class Application extends BaseModel
{
    protected $guarded = [];

    protected static function booted()
    {
        static::saving(function ($application) {
            if ($application->fqdn == '') {
                $application->fqdn = null;
            }
            $application->forceFill([
                'fqdn' => $application->fqdn,
                'install_command' => Str::of($application->install_command)->trim(),
                'build_command' => Str::of($application->build_command)->trim(),
                'start_command' => Str::of($application->start_command)->trim(),
                'base_directory' => Str::of($application->base_directory)->trim(),
                'publish_directory' => Str::of($application->publish_directory)->trim(),
            ]);
        });
        static::created(function ($application) {
            ApplicationSetting::create([
                'application_id' => $application->id,
            ]);
        });
        static::deleting(function ($application) {
            $application->settings()->delete();
            $storages = $application->persistentStorages()->get();
            $server = data_get($application, 'destination.server');
            if ($server) {
                foreach ($storages as $storage) {
                    instant_remote_process(["docker volume rm -f $storage->name"], $server, false);
                }
            }
            $application->persistentStorages()->delete();
            $application->environment_variables()->delete();
            $application->environment_variables_preview()->delete();
        });
    }

    public function settings()
    {
        return $this->hasOne(ApplicationSetting::class);
    }

    public function persistentStorages()
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }
    public function fileStorages()
    {
        return $this->morphMany(LocalFileVolume::class, 'resource');
    }

    public function type()
    {
        return 'application';
    }

    public function publishDirectory(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? '/' . ltrim($value, '/') : null,
        );
    }

    public function gitBranchLocation(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (!is_null($this->source?->html_url) && !is_null($this->git_repository) && !is_null($this->git_branch)) {
                    return "{$this->source->html_url}/{$this->git_repository}/tree/{$this->git_branch}";
                }
                return $this->git_repository;
            }

        );
    }

    public function gitCommits(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (!is_null($this->source?->html_url) && !is_null($this->git_repository) && !is_null($this->git_branch)) {
                    return "{$this->source->html_url}/{$this->git_repository}/commits/{$this->git_branch}";
                }
                return $this->git_repository;
            }
        );
    }
    public function dockerfileLocation(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if (is_null($value) || $value === '') {
                    return '/Dockerfile';
                } else {
                    if ($value !== '/') {
                        return Str::start(Str::replaceEnd('/', '', $value), '/');
                    }
                    return Str::start($value, '/');
                }
            }
        );
    }
    public function baseDirectory(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => '/' . ltrim($value, '/'),
        );
    }

    public function portsMappings(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === "" ? null : $value,
        );
    }

    // Normal Deployments

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

    public function environment_variables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class)->where('is_preview', false)->orderBy('key', 'asc');
    }

    public function runtime_environment_variables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class)->where('is_preview', false)->where('key', 'not like', 'NIXPACKS_%');
    }

    // Preview Deployments

    public function build_environment_variables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class)->where('is_preview', false)->where('is_build_time', true)->where('key', 'not like', 'NIXPACKS_%');
    }

    public function nixpacks_environment_variables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class)->where('is_preview', false)->where('key', 'like', 'NIXPACKS_%');
    }

    public function environment_variables_preview(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class)->where('is_preview', true)->orderBy('key', 'asc');
    }

    public function runtime_environment_variables_preview(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class)->where('is_preview', true)->where('key', 'not like', 'NIXPACKS_%');
    }

    public function build_environment_variables_preview(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class)->where('is_preview', true)->where('is_build_time', true)->where('key', 'not like', 'NIXPACKS_%');
    }

    public function nixpacks_environment_variables_preview(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class)->where('is_preview', true)->where('key', 'like', 'NIXPACKS_%');
    }

    public function private_key()
    {
        return $this->belongsTo(PrivateKey::class);
    }

    public function environment()
    {
        return $this->belongsTo(Environment::class);
    }

    public function previews()
    {
        return $this->hasMany(ApplicationPreview::class);
    }

    public function destination()
    {
        return $this->morphTo();
    }

    public function source()
    {
        return $this->morphTo();
    }

    public function isDeploymentInprogress() {
        $deployments = ApplicationDeploymentQueue::where('application_id', $this->id)->where('status', 'in_progress')->count();
        if ($deployments > 0) {
            return true;
        }
        return false;
    }

    public function deployments(int $skip = 0, int $take = 10)
    {
        $deployments = ApplicationDeploymentQueue::where('application_id', $this->id)->orderBy('created_at', 'desc');
        $count = $deployments->count();
        $deployments = $deployments->skip($skip)->take($take)->get();
        return [
            'count' => $count,
            'deployments' => $deployments
        ];
    }

    public function get_deployment(string $deployment_uuid)
    {
        return Activity::where('subject_id', $this->id)->where('properties->type_uuid', '=', $deployment_uuid)->first();
    }

    public function isDeployable(): bool
    {
        if ($this->settings->is_auto_deploy_enabled) {
            return true;
        }
        return false;
    }

    public function isPRDeployable(): bool
    {
        if ($this->settings->is_preview_deployments_enabled) {
            return true;
        }
        return false;
    }

    public function deploymentType()
    {
        if (data_get($this, 'private_key_id')) {
            return 'deploy_key';
        } else if (data_get($this, 'source')) {
            return 'source';
        } else {
            return 'other';
        }
        throw new \Exception('No deployment type found');
    }
    public function could_set_build_commands(): bool
    {
        if ($this->build_pack === 'nixpacks') {
            return true;
        }
        return false;
    }
    public function git_based(): bool
    {
        if ($this->dockerfile) {
            return false;
        }
        if ($this->build_pack === 'dockerimage') {
            return false;
        }
        return true;
    }
    public function isHealthcheckDisabled(): bool
    {
        if (data_get($this, 'health_check_enabled') === false) {
            return true;
        }
        return false;
    }
    public function isConfigurationChanged($save = false)
    {
        $newConfigHash = $this->fqdn . $this->git_repository . $this->git_branch . $this->git_commit_sha . $this->build_pack . $this->static_image . $this->install_command  . $this->build_command . $this->start_command . $this->port_exposes . $this->port_mappings . $this->base_directory . $this->publish_directory . $this->health_check_path  . $this->health_check_port . $this->health_check_host . $this->health_check_method . $this->health_check_return_code . $this->health_check_scheme . $this->health_check_response_text . $this->health_check_interval . $this->health_check_timeout . $this->health_check_retries . $this->health_check_start_period . $this->health_check_enabled . $this->limits_memory  . $this->limits_swap . $this->limits_swappiness . $this->limits_reservation . $this->limits_cpus . $this->limits_cpuset . $this->limits_cpu_shares . $this->dockerfile . $this->dockerfile_location . $this->custom_labels;
        if ($this->pull_request_id === 0) {
            $newConfigHash .= json_encode($this->environment_variables->all());
        } else {
            $newConfigHash .= json_encode($this->environment_variables_preview->all());
        }
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
}
