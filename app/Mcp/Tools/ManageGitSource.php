<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class ManageGitSource extends RestActionTool
{
    protected string $name = 'manage_git_source';

    protected string $description = 'List, add, update, or delete GitHub apps (resource=github) and GitLab apps (resource=gitlab) of the authenticated team. Secrets and keys are never returned. Requires read ability for list, write ability for changes. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'list' => ['method' => 'GET', 'paths' => ['github' => 'github-apps', 'gitlab' => 'gitlab-apps'], 'ability' => 'read', 'description' => 'git apps of the team and system-wide apps'],
            'create' => ['method' => 'POST', 'paths' => ['github' => 'github-apps', 'gitlab' => 'gitlab-apps'], 'ability' => 'write', 'description' => 'add a git app (github data: name, html_url, api_url, app_id, installation_id, client_id, client_secret, webhook_secret, private_key_uuid, organization, custom_user, custom_port; gitlab data: name, html_url, api_url, group_name, client_id, client_secret, webhook_token, redirect_uri, custom_user, custom_port)'],
            'update' => ['method' => 'PATCH', 'paths' => ['github' => 'github-apps/{source_id}', 'gitlab' => 'gitlab-apps/{source_id}'], 'ability' => 'write', 'description' => 'update a git app of the team (data: same fields as create, all optional)'],
            'delete' => ['method' => 'DELETE', 'paths' => ['github' => 'github-apps/{source_id}', 'gitlab' => 'gitlab-apps/{source_id}'], 'ability' => 'write', 'confirm' => true, 'description' => 'delete a git app of the team that no application uses'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'source_id' => 'Numeric id of the git app, from the list action (update and delete).',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API for GitHub or GitLab apps.';
    }
}
