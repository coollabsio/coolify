<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// #[OA\Schema(
class DockerRegistry extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
        'token' => 'encrypted',
    ];

    public static function getTypes(): array
    {
        return [
            'docker_hub' => 'Docker Hub',
            'gcr' => 'Google Container Registry',
            'ghcr' => 'GitHub Container Registry',
            'quay' => 'Quay.io',
            'custom' => 'Custom Registry'
        ];
    }
}
