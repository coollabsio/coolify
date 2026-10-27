<?php

namespace App\Http\Controllers\Api;

use OpenApi\Attributes as OA;

#[OA\Info(title: 'Coolify', version: '0.1')]
#[OA\Server(url: 'https://app.coolify.io/api/v1', description: 'Coolify Cloud API. Change the host to your own instance if you are self-hosting.')]
#[OA\SecurityScheme(
    type: 'http',
    scheme: 'bearer',
    securityScheme: 'bearerAuth',
    description: 'Go to `Keys & Tokens` / `API tokens` and create a new token. Use the token as the bearer token.')]
#[OA\Components(
    schemas: [
        new OA\Schema(
            schema: 'DatabaseImportRequest',
            oneOf: [
                new OA\Schema(required: ['source', 'upload_id'], additionalProperties: false, properties: [new OA\Property(property: 'source', type: 'string', enum: ['upload']), new OA\Property(property: 'upload_id', type: 'string', format: 'uuid'), new OA\Property(property: 'dump_all', type: 'boolean', default: false), new OA\Property(property: 'replace_existing', description: 'Drop matching PostgreSQL objects before restoring a single-database archive.', type: 'boolean', default: false), new OA\Property(property: 'keep_owners', description: 'Restore object owners and privileges from a single-database PostgreSQL archive. By default they are skipped, because the roles of another server usually do not exist.', type: 'boolean', default: false), new OA\Property(property: 'restore_mysql_users', description: 'MySQL and MariaDB backups containing all databases: also restore the mysql system database (users, passwords, and privileges). By default the system databases are skipped, so the database keeps its own users and passwords.', type: 'boolean', default: false), new OA\Property(property: 'sqlite_database', description: 'SQLite: the database file to restore into. Must be one of the database files. Defaults to the file named in the backup file name, else the first file.', type: 'string', example: 'app.db')]),
                new OA\Schema(required: ['source', 's3_storage_uuid', 'path'], additionalProperties: false, properties: [new OA\Property(property: 'source', type: 'string', enum: ['s3']), new OA\Property(property: 's3_storage_uuid', type: 'string'), new OA\Property(property: 'path', type: 'string'), new OA\Property(property: 'dump_all', type: 'boolean', default: false), new OA\Property(property: 'replace_existing', description: 'Drop matching PostgreSQL objects before restoring a single-database archive.', type: 'boolean', default: false), new OA\Property(property: 'keep_owners', description: 'Restore object owners and privileges from a single-database PostgreSQL archive. By default they are skipped, because the roles of another server usually do not exist.', type: 'boolean', default: false), new OA\Property(property: 'restore_mysql_users', description: 'MySQL and MariaDB backups containing all databases: also restore the mysql system database (users, passwords, and privileges). By default the system databases are skipped, so the database keeps its own users and passwords.', type: 'boolean', default: false), new OA\Property(property: 'sqlite_database', description: 'SQLite: the database file to restore into. Must be one of the database files. Defaults to the file named in the backup file name, else the first file.', type: 'string', example: 'app.db')]),
                new OA\Schema(required: ['source', 'path'], additionalProperties: false, properties: [new OA\Property(property: 'source', type: 'string', enum: ['server']), new OA\Property(property: 'path', type: 'string', example: '/var/backups/database.sql.gz'), new OA\Property(property: 'dump_all', type: 'boolean', default: false), new OA\Property(property: 'replace_existing', description: 'Drop matching PostgreSQL objects before restoring a single-database archive.', type: 'boolean', default: false), new OA\Property(property: 'keep_owners', description: 'Restore object owners and privileges from a single-database PostgreSQL archive. By default they are skipped, because the roles of another server usually do not exist.', type: 'boolean', default: false), new OA\Property(property: 'restore_mysql_users', description: 'MySQL and MariaDB backups containing all databases: also restore the mysql system database (users, passwords, and privileges). By default the system databases are skipped, so the database keeps its own users and passwords.', type: 'boolean', default: false), new OA\Property(property: 'sqlite_database', description: 'SQLite: the database file to restore into. Must be one of the database files. Defaults to the file named in the backup file name, else the first file.', type: 'string', example: 'app.db')]),
            ],
            type: 'object',
        ),
        new OA\Schema(
            schema: 'DatabaseImportStatus',
            type: 'object',
            properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'status', type: 'string', enum: ['queued', 'in_progress', 'finished', 'error', 'killed', 'cancelled', 'closed']),
                new OA\Property(property: 'exit_code', type: 'integer', nullable: true),
                new OA\Property(property: 'output', type: 'string'),
                new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
                new OA\Property(property: 'finished_at', type: 'string', format: 'date-time', nullable: true),
            ],
        ),
        new OA\Schema(
            schema: 'Database',
            description: 'Standalone database. The fields that start with an engine name (for example postgres_ or redis_) are only returned for that engine. Passwords, connection URLs and init_scripts are only returned for tokens with the read:sensitive or root ability.',
            type: 'object',
            properties: [
                new OA\Property(property: 'uuid', type: 'string'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'database_type', type: 'string', enum: ['standalone-postgresql', 'standalone-redis', 'standalone-mongodb', 'standalone-mysql', 'standalone-mariadb', 'standalone-keydb', 'standalone-dragonfly', 'standalone-clickhouse', 'standalone-sqlite']),
                new OA\Property(property: 'image', type: 'string'),
                new OA\Property(property: 'status', type: 'string', description: 'Container status and health, for example running:healthy or exited:unhealthy.'),
                new OA\Property(property: 'server_status', type: 'boolean', description: 'True when the server of the database is reachable and usable.'),
                new OA\Property(property: 'environment_id', type: 'integer'),
                new OA\Property(property: 'environment', ref: '#/components/schemas/Environment', description: 'Only returned by GET /databases/{uuid}. Includes the project.'),
                new OA\Property(property: 'destination_id', type: 'integer'),
                new OA\Property(property: 'destination_type', type: 'string'),
                new OA\Property(
                    property: 'destination',
                    description: 'Docker network destination of the database, with its server.',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'uuid', type: 'string'),
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'network', type: 'string'),
                        new OA\Property(property: 'server_id', type: 'integer'),
                        new OA\Property(property: 'server', ref: '#/components/schemas/Server'),
                        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
                    ],
                ),
                new OA\Property(property: 'is_public', type: 'boolean', description: 'Not returned for SQLite.'),
                new OA\Property(property: 'public_port', type: 'integer', nullable: true, description: 'Not returned for SQLite.'),
                new OA\Property(property: 'public_port_timeout', type: 'integer', nullable: true, description: 'Proxy timeout of the public port in seconds. Not returned for SQLite.'),
                new OA\Property(property: 'ports_mappings', type: 'string', nullable: true, description: 'Comma-separated host:container port mappings. Not returned for SQLite.'),
                new OA\Property(property: 'enable_ssl', type: 'boolean', description: 'Not returned for ClickHouse and SQLite.'),
                new OA\Property(property: 'ssl_mode', type: 'string', nullable: true, description: 'PostgreSQL, MongoDB and MySQL only.'),
                new OA\Property(property: 'internal_db_url', type: 'string', nullable: true, description: 'Connection URL inside the Docker network. Contains the password. Not returned for SQLite.'),
                new OA\Property(property: 'external_db_url', type: 'string', nullable: true, description: 'Public connection URL, when the database is public. Contains the password. Not returned for SQLite.'),
                new OA\Property(property: 'init_scripts', type: 'array', nullable: true, items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'index', type: 'integer'), new OA\Property(property: 'filename', type: 'string'), new OA\Property(property: 'content', type: 'string')]), description: 'PostgreSQL only.'),
                new OA\Property(property: 'custom_docker_run_options', type: 'string', nullable: true),
                new OA\Property(property: 'is_log_drain_enabled', type: 'boolean'),
                new OA\Property(property: 'is_include_timestamps', type: 'boolean', description: 'Not returned for MariaDB.'),
                new OA\Property(property: 'include_coolify_default_variables', type: 'boolean'),
                new OA\Property(property: 'redact_all_env_values_in_logs', type: 'boolean'),
                new OA\Property(property: 'health_check_enabled', type: 'boolean'),
                new OA\Property(property: 'health_check_interval', type: 'integer'),
                new OA\Property(property: 'health_check_timeout', type: 'integer'),
                new OA\Property(property: 'health_check_retries', type: 'integer'),
                new OA\Property(property: 'health_check_start_period', type: 'integer'),
                new OA\Property(property: 'limits_memory', type: 'string'),
                new OA\Property(property: 'limits_memory_swap', type: 'string'),
                new OA\Property(property: 'limits_memory_swappiness', type: 'integer'),
                new OA\Property(property: 'limits_memory_reservation', type: 'string'),
                new OA\Property(property: 'limits_cpus', type: 'string'),
                new OA\Property(property: 'limits_cpuset', type: 'string', nullable: true),
                new OA\Property(property: 'limits_cpu_shares', type: 'integer'),
                new OA\Property(property: 'config_hash', type: 'string', nullable: true, description: 'Internal hash that Coolify uses to find configuration changes that need a restart. It changes only when the database starts, so do not use it to compare configurations.'),
                new OA\Property(property: 'restart_count', type: 'integer'),
                new OA\Property(property: 'last_restart_at', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'last_restart_type', type: 'string', nullable: true),
                new OA\Property(property: 'last_online_at', type: 'string', nullable: true),
                new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'backup_configs', type: 'array', items: new OA\Items(type: 'object'), description: 'Only returned by GET /databases. The scheduled backups of the database, as returned by GET /databases/{uuid}/backups.'),
                new OA\Property(property: 'postgres_user', type: 'string'),
                new OA\Property(property: 'postgres_password', type: 'string', format: 'password'),
                new OA\Property(property: 'postgres_db', type: 'string'),
                new OA\Property(property: 'postgres_initdb_args', type: 'string', nullable: true),
                new OA\Property(property: 'postgres_host_auth_method', type: 'string', nullable: true),
                new OA\Property(property: 'postgres_conf', type: 'string', nullable: true),
                new OA\Property(property: 'redis_password', type: 'string', format: 'password'),
                new OA\Property(property: 'redis_conf', type: 'string', nullable: true),
                new OA\Property(property: 'mongo_initdb_root_username', type: 'string'),
                new OA\Property(property: 'mongo_initdb_root_password', type: 'string', format: 'password'),
                new OA\Property(property: 'mongo_initdb_database', type: 'string'),
                new OA\Property(property: 'mongo_conf', type: 'string', nullable: true),
                new OA\Property(property: 'mysql_user', type: 'string'),
                new OA\Property(property: 'mysql_password', type: 'string', format: 'password'),
                new OA\Property(property: 'mysql_root_password', type: 'string', format: 'password'),
                new OA\Property(property: 'mysql_database', type: 'string'),
                new OA\Property(property: 'mysql_conf', type: 'string', nullable: true),
                new OA\Property(property: 'mariadb_user', type: 'string'),
                new OA\Property(property: 'mariadb_password', type: 'string', format: 'password'),
                new OA\Property(property: 'mariadb_root_password', type: 'string', format: 'password'),
                new OA\Property(property: 'mariadb_database', type: 'string'),
                new OA\Property(property: 'mariadb_conf', type: 'string', nullable: true),
                new OA\Property(property: 'keydb_password', type: 'string', format: 'password'),
                new OA\Property(property: 'keydb_conf', type: 'string', nullable: true),
                new OA\Property(property: 'dragonfly_password', type: 'string', format: 'password'),
                new OA\Property(property: 'clickhouse_admin_user', type: 'string'),
                new OA\Property(property: 'clickhouse_admin_password', type: 'string', format: 'password'),
                new OA\Property(property: 'clickhouse_db', type: 'string'),
                new OA\Property(property: 'sqlite_databases', type: 'string', description: 'Comma-separated SQLite database file names.'),
                new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
                new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
            ],
        ),
    ],
    responses: [
        new OA\Response(
            response: 400,
            description: 'Invalid token.',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Invalid token.'),
                ]
            )),
        new OA\Response(
            response: 401,
            description: 'Unauthenticated.',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.'),
                ]
            )),
        new OA\Response(
            response: 404,
            description: 'Resource not found.',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Resource not found.'),
                ]
            )),
        new OA\Response(
            response: 422,
            description: 'Validation error.',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Validation error.'),
                    new OA\Property(
                        property: 'errors',
                        type: 'object',
                        additionalProperties: new OA\AdditionalProperties(
                            type: 'array',
                            items: new OA\Items(type: 'string')
                        ),
                        example: [
                            'name' => ['The name field is required.'],
                            'api_url' => ['The api url field is required.', 'The api url format is invalid.'],
                        ]
                    ),
                ]
            )),
        new OA\Response(
            response: 429,
            description: 'Rate limit exceeded.',
            headers: [
                new OA\Header(
                    header: 'Retry-After',
                    description: 'Number of seconds to wait before retrying.',
                    schema: new OA\Schema(type: 'integer', example: 60)
                ),
            ],
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Rate limit exceeded. Please try again later.'),
                ]
            )),
    ],
)]
class OpenApi
{
    // This class is used to generate OpenAPI documentation
    // for the Coolify API. It is not a controller and does
    // not contain any routes. It is used to define the
    // OpenAPI metadata and security scheme for the API.
}
