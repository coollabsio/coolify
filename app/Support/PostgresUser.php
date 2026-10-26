<?php

namespace App\Support;

/**
 * Finds the PostgreSQL user inside a database container, the same way for backups and imports.
 *
 * The official image uses POSTGRES_USER (default "postgres"). Other images use PGUSER (read
 * by libpq) or POSTGRESQL_USERNAME (Bitnami). When none is set, "postgres" is only a guess,
 * so callers must check the connection before they change data.
 */
final class PostgresUser
{
    /**
     * @var list<string>
     */
    public const ENV_NAMES = ['POSTGRES_USER', 'PGUSER', 'POSTGRESQL_USERNAME'];

    public const DEFAULT = 'postgres';

    /**
     * Shell parameter expansion that resolves the user from the container environment.
     */
    public static function shellExpression(): string
    {
        return array_reduce(
            array_reverse(self::ENV_NAMES),
            fn (string $fallback, string $name): string => '${'.$name.':-'.$fallback.'}',
            self::DEFAULT,
        );
    }

    /**
     * Resolves the user from `env` output lines (NAME=value) of the container.
     *
     * @param  iterable<string>  $envLines
     */
    public static function fromEnvLines(iterable $envLines): string
    {
        $values = [];
        foreach ($envLines as $line) {
            [$name, $value] = array_pad(explode('=', trim((string) $line), 2), 2, '');
            $values[$name] = $value;
        }

        foreach (self::ENV_NAMES as $name) {
            if (($values[$name] ?? '') !== '') {
                return $values[$name];
            }
        }

        return self::DEFAULT;
    }
}
