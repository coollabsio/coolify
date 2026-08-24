<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ScheduledVolumeBackup extends BaseModel
{
    public const int DEFAULT_TIMEOUT = 36000;

    protected $fillable = [
        'uuid',
        'backupable_type',
        'backupable_id',
        'team_id',
        's3_storage_id',
        'frequency',
        'enabled',
        'save_s3',
        'disable_local_backup',
        'stop_during_backup',
        'retention_amount_locally',
        'retention_days_locally',
        'retention_max_storage_locally',
        'retention_amount_s3',
        'retention_days_s3',
        'retention_max_storage_s3',
        'timeout',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'save_s3' => 'boolean',
            'disable_local_backup' => 'boolean',
            'stop_during_backup' => 'boolean',
            'retention_amount_locally' => 'integer',
            'retention_days_locally' => 'integer',
            'retention_max_storage_locally' => 'float',
            'retention_amount_s3' => 'integer',
            'retention_days_s3' => 'integer',
            'retention_max_storage_s3' => 'float',
            'timeout' => 'integer',
        ];
    }

    public function scopeForApplication(Builder $query, Application $application): Builder
    {
        $volumeIds = $application->persistentStorages()->pluck('id');
        $directoryIds = $application->fileStorages()
            ->where('is_directory', true)
            ->where('is_host_file', false)
            ->pluck('id');

        return $query->where(function (Builder $query) use ($volumeIds, $directoryIds): void {
            $query->where(function (Builder $query) use ($volumeIds): void {
                $query->where('backupable_type', (new LocalPersistentVolume)->getMorphClass())
                    ->whereIn('backupable_id', $volumeIds);
            })->orWhere(function (Builder $query) use ($directoryIds): void {
                $query->where('backupable_type', (new LocalFileVolume)->getMorphClass())
                    ->whereIn('backupable_id', $directoryIds);
            });
        });
    }

    public function scopeForService(Builder $query, Service $service): Builder
    {
        $resources = $service->applications()->get()->concat($service->databases()->get());
        if ($resources->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }
        $resourceIdsByType = $resources->groupBy(fn (Model $resource): string => $resource->getMorphClass());

        $volumeIds = LocalPersistentVolume::query()
            ->where(function (Builder $query) use ($resourceIdsByType): void {
                foreach ($resourceIdsByType as $type => $resources) {
                    $query->orWhere(fn (Builder $query) => $query
                        ->where('resource_type', $type)
                        ->whereIn('resource_id', $resources->pluck('id')));
                }
            })->pluck('id');
        $directoryIds = LocalFileVolume::query()
            ->where('is_directory', true)
            ->where('is_host_file', false)
            ->where(function (Builder $query) use ($resourceIdsByType): void {
                foreach ($resourceIdsByType as $type => $resources) {
                    $query->orWhere(fn (Builder $query) => $query
                        ->where('resource_type', $type)
                        ->whereIn('resource_id', $resources->pluck('id')));
                }
            })->pluck('id');

        return $query->where(function (Builder $query) use ($volumeIds, $directoryIds): void {
            $query->where(fn (Builder $query) => $query
                ->where('backupable_type', (new LocalPersistentVolume)->getMorphClass())
                ->whereIn('backupable_id', $volumeIds))
                ->orWhere(fn (Builder $query) => $query
                    ->where('backupable_type', (new LocalFileVolume)->getMorphClass())
                    ->whereIn('backupable_id', $directoryIds));
        });
    }

    public function backupable(): MorphTo
    {
        return $this->morphTo();
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function s3(): BelongsTo
    {
        return $this->belongsTo(S3Storage::class, 's3_storage_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(ScheduledVolumeBackupExecution::class)->latest();
    }

    public function latestExecution(): HasOne
    {
        return $this->hasOne(ScheduledVolumeBackupExecution::class)->latestOfMany();
    }

    public function server(): ?Server
    {
        $resource = $this->targetResource();

        if ($resource instanceof ServiceApplication || $resource instanceof ServiceDatabase) {
            return $resource->service?->server?->fresh();
        }

        return $resource?->destination?->server?->fresh();
    }

    public function targetResource(): ?Model
    {
        return $this->backupable?->resource;
    }

    public function targetType(): string
    {
        return $this->backupable instanceof LocalFileVolume ? 'Directory' : 'Volume';
    }

    public function targetName(): string
    {
        return match (true) {
            $this->backupable instanceof LocalFileVolume => $this->backupable->fs_path,
            $this->backupable instanceof LocalPersistentVolume => $this->backupable->name,
            default => 'Unknown storage',
        };
    }

    public function sourcePath(): string
    {
        $target = $this->backupable;

        if ($target instanceof LocalPersistentVolume) {
            return filled($target->host_path) ? $target->host_path : $target->name;
        }

        if (! $target instanceof LocalFileVolume || ! $target->is_directory) {
            throw new \RuntimeException('The backup target is not a directory or persistent volume.');
        }

        $path = str($target->fs_path);
        if ($path->startsWith('.')) {
            $resource = $this->targetResource();
            if (! $resource || ! method_exists($resource, 'workdir')) {
                throw new \RuntimeException('The directory backup workdir is unavailable.');
            }

            return $resource->workdir().$path->after('.')->toString();
        }

        return $path->toString();
    }
}
