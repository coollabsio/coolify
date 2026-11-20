<?php

namespace App\Support;

/**
 * Finds the PostgreSQL user, and the host for Supabase images, inside a database container, the same way for backups and imports.
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
     * The superuser of Supabase images. Their init script connects as this user, and it demotes "postgres".
     */
    public const SUPABASE_ADMIN = 'supabase_admin';

    public static function isSupabaseImage(?string $image): bool
    {
        return preg_match('~(^|/)supabase/postgres(:|@|$)~', (string) $image) === 1;
    }

    /**
     * The host for the clients that Coolify runs in the container (healthcheck, backup, restore), or null for the socket.
     *
     * Supabase images accept socket logins only from postgres and supabase_admin (peer map=supabase_map in
     * pg_hba.conf), so all other users fail with "Peer authentication failed". All Supabase images trust 127.0.0.1.
     */
    public static function clientHost(?string $image): ?string
    {
        return self::isSupabaseImage($image) ? '127.0.0.1' : null;
    }

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
