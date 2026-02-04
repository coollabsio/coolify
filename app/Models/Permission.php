<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Permission reference model.
 *
 * Stores the available permissions that can be assigned to users
 * at the project, environment, or resource level.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $resource_type
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Permission extends Model
{
    protected $fillable = [
        'name',
        'description',
        'resource_type',
    ];

    /**
     * Get all permissions for a specific resource type.
     */
    public static function forResourceType(string $resourceType): Collection
    {
        return static::where('resource_type', $resourceType)->get();
    }

    /**
     * Get all project permissions.
     */
    public static function projectPermissions(): Collection
    {
        return static::forResourceType('project');
    }

    /**
     * Get all environment permissions.
     */
    public static function environmentPermissions(): Collection
    {
        return static::forResourceType('environment');
    }

    /**
     * Get all server permissions.
     */
    public static function serverPermissions(): Collection
    {
        return static::forResourceType('server');
    }

    /**
     * Get all service permissions.
     */
    public static function servicePermissions(): Collection
    {
        return static::forResourceType('service');
    }

    /**
     * Get all database permissions.
     */
    public static function databasePermissions(): Collection
    {
        return static::forResourceType('database');
    }

    /**
     * Get all application permissions.
     */
    public static function applicationPermissions(): Collection
    {
        return static::forResourceType('application');
    }

    /**
     * Scope to filter by resource type.
     */
    public function scopeOfType(Builder $query, string $resourceType): Builder
    {
        return $query->where('resource_type', $resourceType);
    }

    /**
     * Get the short name of the permission (without resource prefix).
     * e.g., 'project.view' returns 'view'
     */
    public function getShortNameAttribute(): string
    {
        $parts = explode('.', $this->name);

        return end($parts);
    }

    /**
     * Get all available permission names as an array.
     */
    public static function allNames(): array
    {
        return static::pluck('name')->toArray();
    }

    /**
     * Get default full-access permissions array for a resource type.
     */
    public static function getFullAccessPermissions(string $resourceType): array
    {
        $permissions = static::forResourceType($resourceType);
        $result = [];

        foreach ($permissions as $permission) {
            $result[$permission->short_name] = true;
        }

        return $result;
    }

    /**
     * Get default view-only permissions array for a resource type.
     */
    public static function getViewOnlyPermissions(string $resourceType): array
    {
        return ['view' => true];
    }
}
