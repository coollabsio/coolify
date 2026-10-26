<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class CreateApplication extends RestActionTool
{
    protected string $name = 'create_application';

    protected string $description = 'Create an application in a project of the authenticated team, from a public Git repository, a private repository (GitHub App or deploy key), a Dockerfile, or a Docker image. Requires write ability (instant_deploy and manual webhook secrets also need deploy). Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'public' => ['method' => 'POST', 'path' => 'applications/public', 'ability' => 'write', 'description' => 'create from a public Git repository (data: git_repository, git_branch, build_pack, ports_exposes)'],
            'github_app' => ['method' => 'POST', 'path' => 'applications/private-github-app', 'ability' => 'write', 'description' => 'create from a private repository through a GitHub App (data: github_app_uuid, git_repository, git_branch, build_pack, ports_exposes)'],
            'deploy_key' => ['method' => 'POST', 'path' => 'applications/private-deploy-key', 'ability' => 'write', 'description' => 'create from a private repository with a deploy key (data: private_key_uuid, git_repository, git_branch, build_pack, ports_exposes)'],
            'dockerfile' => ['method' => 'POST', 'path' => 'applications/dockerfile', 'ability' => 'write', 'description' => 'create from a base64 encoded Dockerfile (data: dockerfile, ports_exposes)'],
            'docker_image' => ['method' => 'POST', 'path' => 'applications/dockerimage', 'ability' => 'write', 'description' => 'create from a Docker image (data: docker_registry_image_name, docker_registry_image_tag, ports_exposes)'],
        ];
    }

    protected function pathParams(): array
    {
        return [];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API. Always: project_uuid, environment_name or environment_uuid, server_uuid, destination_uuid (required when the server has several destinations). '
            .'public / github_app / deploy_key: git_repository, git_branch, build_pack (nixpacks | railpack | static | dockerfile | dockercompose), ports_exposes (not for dockercompose); github_app also needs github_app_uuid, deploy_key needs private_key_uuid. '
            .'dockerfile: dockerfile (base64). docker_image: docker_registry_image_name, docker_registry_image_tag, ports_exposes. '
            .'Optional: name, description, domains, autogenerate_domain, git_commit_sha, base_directory, publish_directory, install_command, build_command, start_command, docker_compose_location, docker_compose_domains, is_static, is_spa, health_check_*, limits_*, tags, instant_deploy (needs deploy ability).';
    }
}
