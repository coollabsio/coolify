<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class DockerRegistry extends BaseModel
{
    protected $fillable = [
        'server_id',
        'name',
        'registry_url',
        'username',
        'password',
        'is_active',
        'last_validated_at',
    ];

    protected $casts = [
        'password' => 'encrypted',
        'is_active' => 'boolean',
        'last_validated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function ($registry) {
            $registry->registry_url = rtrim($registry->registry_url, '/');

            if (empty($registry->name)) {
                $registry->name = $registry->registry_url;
            }

            if (self::registryExists($registry->server_id, $registry->registry_url, $registry->id)) {
                throw ValidationException::withMessages([
                    'registry_url' => ['This registry already exists for this server.'],
                ]);
            }
        });
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public static function registryExists(int $serverId, string $registryUrl, ?int $excludeId = null): bool
    {
        $query = self::where('server_id', $serverId)
            ->where('registry_url', $registryUrl);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public static function getCommonRegistries(): array
    {
        return [
            [
                'name' => 'Docker Hub',
                'registry_url' => 'https://index.docker.io/v1/',
                'placeholder_username' => 'your-dockerhub-username',
            ],
            [
                'name' => 'GitHub Container Registry',
                'registry_url' => 'ghcr.io',
                'placeholder_username' => 'your-github-username',
            ],
            [
                'name' => 'GitLab Container Registry',
                'registry_url' => 'registry.gitlab.com',
                'placeholder_username' => 'your-gitlab-username',
            ],
            [
                'name' => 'Amazon ECR',
                'registry_url' => 'AWS_ACCOUNT_ID.dkr.ecr.REGION.amazonaws.com',
                'placeholder_username' => 'AWS',
            ],
            [
                'name' => 'Google Container Registry',
                'registry_url' => 'gcr.io',
                'placeholder_username' => '_json_key',
            ],
            [
                'name' => 'Azure Container Registry',
                'registry_url' => 'YOUR_REGISTRY.azurecr.io',
                'placeholder_username' => 'your-registry-name',
            ],
            [
                'name' => 'Quay.io',
                'registry_url' => 'quay.io',
                'placeholder_username' => 'your-quay-username',
            ],
        ];
    }
}
