<?php

namespace App\Services\Infisical;

use Illuminate\Support\Str;

/**
 * Derives Infisical folder paths from Coolify names.
 *
 * Pure: no I/O, no database, no model dependencies. Coolify project and
 * resource names are not unique, so any caller enumerating siblings must run
 * assertNoCollisions() before building paths from them — two names slugging
 * to one folder would silently merge unrelated secrets.
 */
class InfisicalPath
{
    public static function forTeam(): string
    {
        return '/';
    }

    public static function forProject(string $projectName): string
    {
        return '/'.self::segment($projectName).'/';
    }

    public static function forResource(string $projectName, string $resourceName): string
    {
        return '/'.self::segment($projectName).'/'.self::segment($resourceName).'/';
    }

    public static function environmentSlug(string $environmentName): string
    {
        return self::segment($environmentName);
    }

    /**
     * @param  array<int, string>  $names
     *
     * @throws InfisicalPathCollisionException
     */
    public static function assertNoCollisions(array $names): void
    {
        $seen = [];
        foreach ($names as $name) {
            $slug = self::segment($name);
            if (isset($seen[$slug]) && $seen[$slug] !== $name) {
                throw new InfisicalPathCollisionException(
                    "Infisical folder name collision: \"{$seen[$slug]}\" and \"{$name}\" both resolve to \"{$slug}\"."
                );
            }
            $seen[$slug] = $name;
        }
    }

    /**
     * @throws InfisicalPathCollisionException
     */
    private static function segment(string $name): string
    {
        // Str::slug() DELETES slashes rather than converting them to
        // separators, so "a/b" would slug to "ab" and collide with a sibling
        // literally named "ab". Convert to a separator first. Verified:
        // Str::slug('a/b') === 'ab'.
        $slug = Str::slug(str_replace('/', '-', $name));

        if ($slug === '') {
            throw new InfisicalPathCollisionException(
                "Coolify name \"{$name}\" resolves to an empty folder name and cannot be synced to Infisical."
            );
        }

        return $slug;
    }
}
