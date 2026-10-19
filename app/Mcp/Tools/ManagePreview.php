<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class ManagePreview extends RestActionTool
{
    protected string $name = 'manage_preview';

    protected string $description = 'Create (and deploy), update the domains of, or delete a pull request preview deployment of an application of the authenticated team. create and delete require deploy ability, update requires write. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'create' => ['method' => 'POST', 'path' => 'applications/{uuid}/previews', 'ability' => 'deploy', 'description' => 'open or redeploy a preview (data: pull_request_id, pull_request_html_url, git_type, commit, docker_tag, force, instant_deploy)'],
            'update' => ['method' => 'PATCH', 'path' => 'applications/{uuid}/previews/{pull_request_id}', 'ability' => 'write', 'description' => 'replace preview domains (data: domains, or docker_compose_domains for compose apps, force_domain_override)'],
            'delete' => ['method' => 'DELETE', 'path' => 'applications/{uuid}/previews/{pull_request_id}', 'ability' => 'deploy', 'confirm' => true, 'description' => 'delete the preview and its containers'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Application UUID.',
            'pull_request_id' => 'Pull request ID of the preview (update and delete).',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API. create: pull_request_id (required), pull_request_html_url, git_type (github | gitlab | gitea | bitbucket), commit, docker_tag (Docker image apps), force, instant_deploy; update: domains or docker_compose_domains, force_domain_override.';
    }
}
