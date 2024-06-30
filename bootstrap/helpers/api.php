<?php

use App\Enums\BuildPackTypes;
use App\Enums\RedirectTypes;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;

function get_team_id_from_token()
{
    $token = auth()->user()->currentAccessToken();

    return data_get($token, 'team_id');
}
function invalid_token()
{
    return response()->json(['error' => 'Invalid token.', 'docs' => 'https://coolify.io/docs/api-reference/authorization'], 400);
}

function serialize_api_response($data)
{
    if (! $data instanceof Collection) {
        $data = collect($data);
    }
    $data = $data->sortKeys();
    $created_at = data_get($data, 'created_at');
    $updated_at = data_get($data, 'updated_at');
    if ($created_at) {
        unset($data['created_at']);
        $data['created_at'] = $created_at;

    }
    if ($updated_at) {
        unset($data['updated_at']);
        $data['updated_at'] = $updated_at;
    }
    if (data_get($data, 'id')) {
        $data = $data->prepend($data['id'], 'id');
    }

    return $data;
}

function sharedDataApplications()
{
    return [
        'git_repository' => 'string',
        'git_branch' => 'string',
        'build_pack' => Rule::enum(BuildPackTypes::class),
        'is_static' => 'boolean',
        'domains' => 'string',
        'redirect' => Rule::enum(RedirectTypes::class),
        'git_commit_sha' => 'string',
        'docker_registry_image_name' => 'string|nullable',
        'docker_registry_image_tag' => 'string|nullable',
        'install_command' => 'string|nullable',
        'build_command' => 'string|nullable',
        'start_command' => 'string|nullable',
        'ports_exposes' => 'string|regex:/^(\d+)(,\d+)*$/',
        'ports_mappings' => 'string|regex:/^(\d+:\d+)(,\d+:\d+)*$/|nullable',
        'base_directory' => 'string|nullable',
        'publish_directory' => 'string|nullable',
        'health_check_enabled' => 'boolean',
        'health_check_path' => 'string',
        'health_check_port' => 'string|nullable',
        'health_check_host' => 'string',
        'health_check_method' => 'string',
        'health_check_return_code' => 'numeric',
        'health_check_scheme' => 'string',
        'health_check_response_text' => 'string|nullable',
        'health_check_interval' => 'numeric',
        'health_check_timeout' => 'numeric',
        'health_check_retries' => 'numeric',
        'health_check_start_period' => 'numeric',
        'limits_memory' => 'string',
        'limits_memory_swap' => 'string',
        'limits_memory_swappiness' => 'numeric',
        'limits_memory_reservation' => 'string',
        'limits_cpus' => 'string',
        'limits_cpuset' => 'string|nullable',
        'limits_cpu_shares' => 'numeric',
        'custom_labels' => 'string|nullable',
        'custom_docker_run_options' => 'string|nullable',
        'post_deployment_command' => 'string|nullable',
        'post_deployment_command_container' => 'string',
        'pre_deployment_command' => 'string|nullable',
        'pre_deployment_command_container' => 'string',
        'manual_webhook_secret_github' => 'string|nullable',
        'manual_webhook_secret_gitlab' => 'string|nullable',
        'manual_webhook_secret_bitbucket' => 'string|nullable',
        'manual_webhook_secret_gitea' => 'string|nullable',
    ];
}
