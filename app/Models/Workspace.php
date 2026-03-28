<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property-read string $id
 * @property-read string $name
 * @property-read string|null $description
 * @property-read int $concurrent_builds
 * @property-read int $default_deployment_timeout
 * @property-read bool $is_2fa_required
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
final class Workspace extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'name' => 'string',
            'description' => 'string',
            'concurrent_builds' => 'integer',
            'default_deployment_timeout' => 'integer',
            'is_2fa_required' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
