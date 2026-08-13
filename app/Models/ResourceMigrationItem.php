<?php

namespace App\Models;

use App\Enums\ResourceMigrationStatus;
use Database\Factories\ResourceMigrationItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceMigrationItem extends BaseModel
{
    /** @use HasFactory<ResourceMigrationItemFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'resource_migration_id',
        'resource_type',
        'source_uuid',
        'target_uuid',
        'name',
        'status',
        'sort_order',
        'archives',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'status' => ResourceMigrationStatus::class,
            'archives' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function migration(): BelongsTo
    {
        return $this->belongsTo(ResourceMigration::class, 'resource_migration_id');
    }

    public function mark(ResourceMigrationStatus $status, ?string $error = null): void
    {
        $this->update([
            'status' => $status,
            'error' => $error,
        ]);
    }
}
