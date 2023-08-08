<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Activity;

class Application extends BaseModel
{
    protected $fillable = [
        'name',
        'repository_project_id',
        'project_id',
        'description',
        'git_repository',
        'git_branch',
        'git_full_url',
        'build_pack',
        'environment_id',
        'destination_id',
        'destination_type',
        'source_id',
        'source_type',
        'ports_mappings',
        'ports_exposes',
        'publish_directory',
        'private_key_id'
    ];

    protected static function booted()
    {
        static::created(function ($application) {
            ApplicationSetting::create([
                'application_id' => $application->id,
            ]);
        });
        static::deleting(function ($application) {
            $application->settings()->delete();
            $application->persistentStorages()->delete();
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

    public function type()
    {
        return 'application';
    }

    public function publishDirectory(): Attribute
    {
        return Attribute::make(
            set: fn($value) => $value ? '/' . ltrim($value, '/') : null,
        );
    }

    public function gitBranchLocation(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (!is_null($this->source?->html_url) && !is_null($this->git_repository) && !is_null($this->git_branch)) {
                    return "{$this->source->html_url}/{$this->git_repository}/tree/{$this->git_branch}";
                }
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
            }
        );
    }

    public function baseDirectory(): Attribute
    {
        return Attribute::make(
            set: fn($value) => '/' . ltrim($value, '/'),
        );
    }

    public function portsMappings(): Attribute
    {
        return Attribute::make(
            set: fn($value) => $value === "" ? null : $value,
        );
    }

    // Normal Deployments

    public function portsMappingsArray(): Attribute
    {
        return Attribute::make(
            get: fn() => is_null($this->ports_mappings)
                ? []
                : explode(',', $this->ports_mappings),

        );
    }

    public function portsExposesArray(): Attribute
    {
        return Attribute::make(
            get: fn() => is_null($this->ports_exposes)
                ? []
                : explode(',', $this->ports_exposes)
        );
    }

    public function environment_variables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class)->where('is_preview', false);
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
        return $this->hasMany(EnvironmentVariable::class)->where('is_preview', true);
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
        }
        if (data_get($this, 'source')) {
            return 'source';
        }
        if (data_get($this, 'private_key_id')) {
            return 'deploy_key';
        }
        throw new \Exception('No deployment type found');
    }
}
