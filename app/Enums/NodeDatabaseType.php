<?php

namespace App\Enums;

/**
 * The database engines that run on Nodes, with the v4 standalone database defaults.
 * SQLite is not here: it has no network port, and its read-write volume mounts into
 * one container, so no other workload could reach it (decision 0007).
 */
enum NodeDatabaseType: string
{
    case POSTGRESQL = 'postgresql';
    case MYSQL = 'mysql';
    case MARIADB = 'mariadb';
    case MONGODB = 'mongodb';
    case REDIS = 'redis';
    case KEYDB = 'keydb';
    case DRAGONFLY = 'dragonfly';
    case CLICKHOUSE = 'clickhouse';

    public function label(): string
    {
        return match ($this) {
            self::POSTGRESQL => 'PostgreSQL',
            self::MYSQL => 'MySQL',
            self::MARIADB => 'MariaDB',
            self::MONGODB => 'MongoDB',
            self::REDIS => 'Redis',
            self::KEYDB => 'KeyDB',
            self::DRAGONFLY => 'Dragonfly',
            self::CLICKHOUSE => 'ClickHouse',
        };
    }

    public function defaultImage(): string
    {
        return match ($this) {
            self::POSTGRESQL => 'postgres:16-alpine',
            self::MYSQL => 'mysql:8',
            self::MARIADB => 'mariadb:11',
            self::MONGODB => 'mongo:7',
            self::REDIS => 'redis:7.2',
            self::KEYDB => 'eqalpha/keydb:latest',
            self::DRAGONFLY => 'docker.dragonflydb.io/dragonflydb/dragonfly',
            self::CLICKHOUSE => 'clickhouse/clickhouse-server:25.11',
        };
    }

    public function port(): int
    {
        return match ($this) {
            self::POSTGRESQL => 5432,
            self::MYSQL, self::MARIADB => 3306,
            self::MONGODB => 27017,
            self::REDIS, self::KEYDB, self::DRAGONFLY => 6379,
            self::CLICKHOUSE => 9000,
        };
    }

    /** The directory of the data volume. PostgreSQL 18 and later keep their data one level higher, like in v4. */
    public function dataDirectory(string $image): string
    {
        if ($this === self::POSTGRESQL) {
            $majorVersion = preg_match('/:(?:pg)?(\d+)/i', $image, $matches) === 1 ? (int) $matches[1] : 0;

            return $majorVersion >= 18 ? '/var/lib/postgresql' : '/var/lib/postgresql/data';
        }

        return match ($this) {
            self::MYSQL, self::MARIADB => '/var/lib/mysql',
            self::MONGODB => '/data/db',
            self::REDIS, self::KEYDB, self::DRAGONFLY => '/data',
            self::CLICKHOUSE => '/var/lib/clickhouse',
        };
    }

    /**
     * The environment variables that carry the credentials, with the v4 names and defaults. A null
     * default is a generated password. A `file` variable is a secret file at /run/secrets/<key>,
     * which the image reads through `<key>_FILE` (decision 0008).
     *
     * @return list<array{key: string, label: string, default: ?string, file: bool}>
     */
    public function credentials(): array
    {
        return match ($this) {
            self::POSTGRESQL => [
                ['key' => 'POSTGRES_USER', 'label' => 'Username', 'default' => 'postgres', 'file' => false],
                ['key' => 'POSTGRES_PASSWORD', 'label' => 'Password', 'default' => null, 'file' => true],
                ['key' => 'POSTGRES_DB', 'label' => 'Initial database', 'default' => 'postgres', 'file' => false],
            ],
            self::MYSQL => [
                ['key' => 'MYSQL_ROOT_PASSWORD', 'label' => 'Root password', 'default' => null, 'file' => true],
                ['key' => 'MYSQL_USER', 'label' => 'Normal user', 'default' => 'mysql', 'file' => false],
                ['key' => 'MYSQL_PASSWORD', 'label' => 'Normal user password', 'default' => null, 'file' => true],
                ['key' => 'MYSQL_DATABASE', 'label' => 'Initial database', 'default' => 'default', 'file' => false],
            ],
            self::MARIADB => [
                ['key' => 'MARIADB_ROOT_PASSWORD', 'label' => 'Root password', 'default' => null, 'file' => true],
                ['key' => 'MARIADB_USER', 'label' => 'Normal user', 'default' => 'mariadb', 'file' => false],
                ['key' => 'MARIADB_PASSWORD', 'label' => 'Normal user password', 'default' => null, 'file' => true],
                ['key' => 'MARIADB_DATABASE', 'label' => 'Initial database', 'default' => 'default', 'file' => false],
            ],
            self::MONGODB => [
                ['key' => 'MONGO_INITDB_ROOT_USERNAME', 'label' => 'Initial username', 'default' => 'root', 'file' => false],
                ['key' => 'MONGO_INITDB_ROOT_PASSWORD', 'label' => 'Initial password', 'default' => null, 'file' => true],
                ['key' => 'MONGO_INITDB_DATABASE', 'label' => 'Initial database', 'default' => 'default', 'file' => false],
            ],
            self::REDIS => [
                ['key' => 'REDIS_USERNAME', 'label' => 'Username', 'default' => 'default', 'file' => false],
                ['key' => 'REDIS_PASSWORD', 'label' => 'Password', 'default' => null, 'file' => false],
            ],
            self::KEYDB, self::DRAGONFLY => [
                ['key' => 'REDIS_PASSWORD', 'label' => 'Password', 'default' => null, 'file' => false],
            ],
            self::CLICKHOUSE => [
                ['key' => 'CLICKHOUSE_USER', 'label' => 'Username', 'default' => 'default', 'file' => false],
                ['key' => 'CLICKHOUSE_PASSWORD', 'label' => 'Password', 'default' => null, 'file' => false],
                ['key' => 'CLICKHOUSE_DB', 'label' => 'Database', 'default' => 'default', 'file' => false],
            ],
        };
    }

    /**
     * The start command, or an empty list for the image default. The shell reads the password from
     * the environment of the container, so it is not stored in the revision. Redis allows `DEBUG`
     * from inside the container only: the restore of a dump reloads it with `DEBUG RELOAD`.
     *
     * @return list<string>
     */
    public function startCommand(): array
    {
        return match ($this) {
            self::REDIS => ['sh', '-c', 'exec redis-server --requirepass "$REDIS_PASSWORD" --appendonly yes --enable-debug-command local'],
            self::KEYDB => ['sh', '-c', 'exec keydb-server --requirepass "$REDIS_PASSWORD" --appendonly yes'],
            self::DRAGONFLY => ['sh', '-c', 'exec dragonfly --requirepass "$REDIS_PASSWORD"'],
            default => [],
        };
    }

    /**
     * The health check columns of the workload, with the v4 database intervals. The commands need no
     * credentials, because a health check command cannot read variables.
     *
     * @return array<string, mixed>
     */
    public function healthCheckSettings(): array
    {
        $check = match ($this) {
            self::POSTGRESQL => ['health_check_type' => 'cmd', 'health_check_command' => 'pg_isready -h 127.0.0.1'],
            self::MYSQL => ['health_check_type' => 'cmd', 'health_check_command' => 'mysqladmin ping -h 127.0.0.1'],
            self::MARIADB => ['health_check_type' => 'cmd', 'health_check_command' => 'healthcheck.sh --connect --innodb_initialized'],
            self::MONGODB => ['health_check_type' => 'cmd', 'health_check_command' => 'mongosh --quiet --eval 1'],
            self::REDIS, self::DRAGONFLY => ['health_check_type' => 'cmd', 'health_check_command' => 'redis-cli ping'],
            self::KEYDB => ['health_check_type' => 'cmd', 'health_check_command' => 'keydb-cli ping'],
            self::CLICKHOUSE => ['health_check_type' => 'http', 'health_check_host' => 'localhost', 'health_check_port' => '8123', 'health_check_path' => '/ping'],
        };

        return [
            ...$check,
            'health_check_enabled' => true,
            'health_check_interval' => 15,
            'health_check_timeout' => 5,
            'health_check_retries' => 5,
            'health_check_start_period' => 5,
        ];
    }

    /**
     * The engine of `database.dump.v1` and `database.restore.v1` (decision 0010), or null when
     * Sentinel has no fixed dump for it. ClickHouse has none: its `BACKUP` writes to a backup disk
     * or to S3 with the S3 key, and a Node never gets the S3 key (decision 0009).
     */
    public function dumpEngine(): ?string
    {
        return $this === self::CLICKHOUSE ? null : $this->value;
    }

    /** The credential variable with the database that a new backup schedule dumps; null when a dump holds the whole instance. */
    public function backupDatabaseKey(): ?string
    {
        return match ($this) {
            self::POSTGRESQL => 'POSTGRES_DB',
            self::MYSQL => 'MYSQL_DATABASE',
            self::MARIADB => 'MARIADB_DATABASE',
            self::MONGODB => 'MONGO_INITDB_DATABASE',
            default => null,
        };
    }

    /**
     * The connection URL from the credential variables, like the v4 internal URL.
     *
     * @param  array<string, string>  $values
     */
    public function connectionUrl(array $values, string $host): string
    {
        $value = fn (string $key): string => rawurlencode($values[$key] ?? '');
        $address = $host.':'.$this->port();

        return match ($this) {
            self::POSTGRESQL => "postgres://{$value('POSTGRES_USER')}:{$value('POSTGRES_PASSWORD')}@{$address}/{$value('POSTGRES_DB')}",
            self::MYSQL => "mysql://{$value('MYSQL_USER')}:{$value('MYSQL_PASSWORD')}@{$address}/{$value('MYSQL_DATABASE')}",
            self::MARIADB => "mysql://{$value('MARIADB_USER')}:{$value('MARIADB_PASSWORD')}@{$address}/{$value('MARIADB_DATABASE')}",
            self::MONGODB => "mongodb://{$value('MONGO_INITDB_ROOT_USERNAME')}:{$value('MONGO_INITDB_ROOT_PASSWORD')}@{$address}/?directConnection=true",
            self::REDIS => "redis://{$value('REDIS_USERNAME')}:{$value('REDIS_PASSWORD')}@{$address}/0",
            self::KEYDB, self::DRAGONFLY => "redis://:{$value('REDIS_PASSWORD')}@{$address}/0",
            self::CLICKHOUSE => "clickhouse://{$value('CLICKHOUSE_USER')}:{$value('CLICKHOUSE_PASSWORD')}@{$address}/{$value('CLICKHOUSE_DB')}",
        };
    }
}
