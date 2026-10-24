<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class ManageService extends RestActionTool
{
    protected string $name = 'manage_service';

    protected string $description = 'Update, delete, clone, or move a service of the authenticated team, update one of its applications or databases, or import a backup into a service database. Requires write ability; delete and import_database also need deploy, and so do instant_deploy, changing is_public of a service database, and clone_volumes. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'update' => ['method' => 'PATCH', 'path' => 'services/{uuid}', 'ability' => 'write', 'description' => 'update the service (data: name, description, docker_compose_raw base64, connect_to_docker_network, urls, force_domain_override, is_container_label_escape_enabled, redact_all_env_values_in_logs, instant_deploy; instant_deploy needs deploy ability)'],
            'delete' => ['method' => 'DELETE', 'path' => 'services/{uuid}', 'ability' => 'deploy', 'confirm' => true, 'description' => 'delete the service (data: delete_volumes, delete_connected_networks, delete_configurations, docker_cleanup)'],
            'clone' => ['method' => 'POST', 'path' => 'services/{uuid}/clone', 'ability' => 'write', 'confirm' => true, 'description' => 'clone the service to a destination of the team (data: destination_uuid, name, clone_volumes; clone_volumes stops the source service while volumes are copied and needs deploy ability)'],
            'move' => ['method' => 'POST', 'path' => 'services/{uuid}/move', 'ability' => 'write', 'confirm' => true, 'description' => 'move the service to another environment of the team (data: environment_uuid)'],
            'update_application' => ['method' => 'PATCH', 'path' => 'services/{uuid}/applications/{app_uuid}', 'ability' => 'write', 'description' => 'update a service application (data: url, noindex_domains, human_name, description, image, exclude_from_status, is_log_drain_enabled, is_gzip_enabled, traefik_certificate_resolver, is_stripprefix_enabled, is_force_https_enabled, max_restart_count)'],
            'update_database' => ['method' => 'PATCH', 'path' => 'services/{uuid}/databases/{database_uuid}', 'ability' => 'write', 'description' => 'update a service database (data: human_name, description, image, exclude_from_status, is_log_drain_enabled, is_public, public_port, public_port_timeout; changing is_public needs deploy ability)'],
            'import_database' => ['method' => 'POST', 'path' => 'services/{uuid}/databases/{database_uuid}/imports', 'ability' => 'deploy', 'confirm' => true, 'description' => 'import a backup into a service database, can overwrite data (data: source s3 | server, s3_storage_uuid, path, dump_all, replace_existing, keep_owners, restore_mysql_users); check progress with get_database_import'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Service UUID.',
            'app_uuid' => 'Service application UUID (update_application).',
            'database_uuid' => 'Service database UUID (update_database, import_database).',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API (see the action descriptions). File uploads are not supported: import only from an S3 storage of the team (source=s3, s3_storage_uuid, path) or a server path (source=server, path).';
    }
}
