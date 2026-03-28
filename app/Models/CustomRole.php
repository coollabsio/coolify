<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Permission;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * @property-read string $id
 * @property-read string $workspace_id
 * @property-read string $name
 * @property-read string $identifier
 * @property-read string|null $description
 * @property-read Collection<int, Permission> $permissions
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
final class CustomRole extends Model
{
    use HasUlids;

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'workspace_id' => 'string',
            'name' => 'string',
            'identifier' => 'string',
            'description' => 'string',
            'permissions' => AsEnumCollection::of(Permission::class),
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
