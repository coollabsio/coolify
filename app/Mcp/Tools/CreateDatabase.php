<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class CreateDatabase extends RestActionTool
{
    protected string $name = 'create_database';

    protected string $description = 'Create a standalone database (postgresql, mysql, mariadb, mongodb, redis, keydb, dragonfly, clickhouse, sqlite) in a project of the authenticated team. Requires write ability (instant_deploy also needs deploy). Connection URLs are not returned. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        $actions = [];
        foreach (['postgresql', 'mysql', 'mariadb', 'mongodb', 'redis', 'keydb', 'dragonfly', 'clickhouse', 'sqlite'] as $type) {
            $actions[$type] = ['method' => 'POST', 'path' => "databases/{$type}", 'ability' => 'write', 'description' => "create a {$type} database"];
        }

        return $actions;
    }

    protected function pathParams(): array
    {
        return [];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API. Required: project_uuid, environment_name or environment_uuid, server_uuid; destination_uuid when the server has several destinations. '
            .'Optional: name, description, image, is_public, public_port (1024-65535), public_port_timeout, limits_memory, limits_memory_swap, limits_memory_swappiness, limits_memory_reservation, limits_cpus, limits_cpuset, limits_cpu_shares, tags, instant_deploy (needs deploy ability). '
            .'Type fields: postgresql: postgres_user, postgres_password, postgres_db, postgres_initdb_args, postgres_host_auth_method, postgres_conf (base64). mysql: mysql_root_password, mysql_user, mysql_password, mysql_database, mysql_conf (base64). '
            .'mariadb: mariadb_root_password, mariadb_user, mariadb_password, mariadb_database, mariadb_conf (base64). mongodb: mongo_initdb_root_username, mongo_initdb_root_password, mongo_initdb_database, mongo_conf (base64). '
            .'redis: redis_password, redis_conf (base64). keydb: keydb_password, keydb_conf (base64). dragonfly: dragonfly_password. clickhouse: clickhouse_admin_user, clickhouse_admin_password. sqlite: sqlite_databases.';
    }
}
