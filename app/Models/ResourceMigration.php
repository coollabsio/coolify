<?php

namespace App\Models;

use App\Enums\MigrationStorageDriver;
use App\Enums\ResourceMigrationDirection;
use App\Enums\ResourceMigrationStatus;
use Database\Factories\ResourceMigrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResourceMigration extends BaseModel
{
    /** @use HasFactory<ResourceMigrationFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'team_id',
        'direction',
        'status',
        'storage_driver',
        'storage_config',
        'manifest',
        'skip_data',
        'destination_uuid',
        'project_uuid',
        'environment_uuid',
        'error',
        'created_by_user_id',
    ];

    protected $hidden = [
        'storage_config',
        'manifest',
    ];

    protected function casts(): array
    {
        return [
            'direction' => ResourceMigrationDirection::class,
            'status' => ResourceMigrationStatus::class,
            'storage_driver' => MigrationStorageDriver::class,
            'storage_config' => 'encrypted:array',
            'manifest' => 'encrypted:array',
            'skip_data' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ResourceMigrationItem::class)->orderBy('sort_order');
    }

    public function markRunning(): void
    {
        $this->update(['status' => ResourceMigrationStatus::Running, 'error' => null]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => ResourceMigrationStatus::Failed,
            'error' => $error,
        ]);
    }

    public function refreshAggregateStatus(): void
    {
        $statuses = $this->items()->pluck('status');

        if ($statuses->isEmpty()) {
            $this->update(['status' => ResourceMigrationStatus::Pending]);

            return;
        }

        $failed = $statuses->contains(ResourceMigrationStatus::Failed);
        $skipped = $statuses->contains(ResourceMigrationStatus::Skipped);
        $pending = $statuses->contains(fn (ResourceMigrationStatus $status) => ! $status->isTerminal());

        if ($pending) {
            $this->update(['status' => ResourceMigrationStatus::Running]);

            return;
        }

        if ($failed || $skipped) {
            $healthy = $statuses->contains(ResourceMigrationStatus::Healthy);
            $this->update([
                'status' => $healthy ? ResourceMigrationStatus::Partial : ResourceMigrationStatus::Failed,
            ]);

            return;
        }

        $this->update(['status' => ResourceMigrationStatus::Completed]);
    }
}
