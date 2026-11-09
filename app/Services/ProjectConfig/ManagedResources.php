<?php

namespace App\Services\ProjectConfig;

use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentVariable;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Services\ProjectConfig\Adapters\StandaloneDatabaseAdapter;
use App\Services\ProjectConfig\Children\EnvironmentVariables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Tells whether coolify.json manages a resource, and guards the variables of managed resources.
 */
class ManagedResources
{
    private static int $applying = 0;

    /**
     * Runs the callback as the coolify.json apply, which may change managed records.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function whileApplying(callable $callback): mixed
    {
        self::$applying++;
        try {
            return $callback();
        } finally {
            self::$applying--;
        }
    }

    public static function isApplying(): bool
    {
        return self::$applying > 0;
    }

    public static function link(?Model $resource): ?EnvironmentConfigLink
    {
        if ($resource instanceof ServiceApplication || $resource instanceof ServiceDatabase) {
            $resource = $resource->service;
        }
        if ($resource === null || $resource->getAttribute('config_key') === null || ! $resource->getAttribute('environment_id')) {
            return null;
        }
        $link = EnvironmentConfigLink::query()->where('environment_id', $resource->getAttribute('environment_id'))->first();

        return $link !== null && ! $link->isSuspended() ? $link : null;
    }

    public static function isManaged(?Model $resource): bool
    {
        return self::link($resource) !== null;
    }

    /**
     * Variables that Coolify itself creates and updates: magic SERVICE_* variables, variables that the
     * Compose file or the template defines, and database variables such as REDIS_PASSWORD.
     */
    public static function isCoolifyVariable(Model $resource, string $key): bool
    {
        $protected = [];
        foreach (StandaloneDatabaseAdapter::TYPES as $type) {
            if ($resource instanceof $type['model']) {
                $protected = $type['variables'];
            }
        }

        return (new EnvironmentVariables($protected))->isGenerated($resource, $key);
    }

    public static function denyReason(Model $resource): string
    {
        $link = self::link($resource);
        $where = $link?->isGit() ? "{$link->file_path} on {$link->branch}" : 'the API';

        return "Managed by coolify.json ({$where}). Change the file, or ask a team admin to suspend the link.";
    }

    /**
     * A managed resource keeps its variables as the file says. Only the value of a `required` variable may change.
     *
     * @throws ValidationException
     */
    public static function guardVariable(EnvironmentVariable $variable, bool $deleting = false): void
    {
        if (self::isApplying()) {
            return;
        }
        if ($variable->exists && ! $deleting && ! $variable->isDirty()) {
            return;
        }
        $resource = $variable->resourceable;
        if (! self::isManaged($resource) || self::isCoolifyVariable($resource, (string) $variable->key)) {
            return;
        }

        $isRequired = ($variable->getOriginal('config_meta')['form'] ?? null) === 'required';
        $onlyValue = array_diff(array_keys($variable->getDirty()), ['value', 'updated_at', 'is_shown_once']) === [];
        if (! $deleting && $variable->exists && $isRequired && $onlyValue) {
            return;
        }

        throw ValidationException::withMessages([
            'key' => [self::denyReason($resource).' Only the values of required variables can be set here.'],
        ]);
    }
}
