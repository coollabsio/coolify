<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Support\Facades\Validator;
use Spatie\Url\Url;

function queue_application_deployment(Application $application, string $deployment_uuid, ?int $pull_request_id = 0, string $commit = 'HEAD', bool $force_rebuild = false, bool $is_webhook = false, bool $is_api = false, bool $restart_only = false, ?string $git_type = null, bool $no_questions_asked = false, ?Server $server = null, ?StandaloneDocker $destination = null, bool $only_this_server = false, bool $rollback = false)
{
    $application_id = $application->id;
    $deployment_link = Url::fromString($application->link()."/deployment/{$deployment_uuid}");
    $deployment_url = $deployment_link->getPath();
    $server_id = $application->destination->server->id;
    $server_name = $application->destination->server->name;
    $destination_id = $application->destination->id;

    if ($server) {
        $server_id = $server->id;
        $server_name = $server->name;
    }
    if ($destination) {
        $destination_id = $destination->id;
    }
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $application_id,
        'application_name' => $application->name,
        'server_id' => $server_id,
        'server_name' => $server_name,
        'destination_id' => $destination_id,
        'deployment_uuid' => $deployment_uuid,
        'deployment_url' => $deployment_url,
        'pull_request_id' => $pull_request_id,
        'force_rebuild' => $force_rebuild,
        'is_webhook' => $is_webhook,
        'is_api' => $is_api,
        'restart_only' => $restart_only,
        'commit' => $commit,
        'rollback' => $rollback,
        'git_type' => $git_type,
        'only_this_server' => $only_this_server,
    ]);

    if ($no_questions_asked) {
        ApplicationDeploymentJob::dispatch(
            application_deployment_queue_id: $deployment->id,
        );
    } elseif (next_queuable($server_id, $application_id)) {
        ApplicationDeploymentJob::dispatch(
            application_deployment_queue_id: $deployment->id,
        );
    }
}
function force_start_deployment(ApplicationDeploymentQueue $deployment)
{
    $deployment->update([
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);

    ApplicationDeploymentJob::dispatch(
        application_deployment_queue_id: $deployment->id,
    );
}
function queue_next_deployment(Application $application)
{
    $server_id = $application->destination->server_id;
    $next_found = ApplicationDeploymentQueue::where('server_id', $server_id)->where('status', ApplicationDeploymentStatus::QUEUED)->get()->sortBy('created_at')->first();
    if ($next_found) {
        $next_found->update([
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);

        ApplicationDeploymentJob::dispatch(
            application_deployment_queue_id: $next_found->id,
        );
    }
}

function next_queuable(string $server_id, string $application_id): bool
{
    $deployments = ApplicationDeploymentQueue::where('server_id', $server_id)->whereIn('status', ['in_progress', ApplicationDeploymentStatus::QUEUED])->get()->sortByDesc('created_at');
    $same_application_deployments = $deployments->where('application_id', $application_id);
    $in_progress = $same_application_deployments->filter(function ($value, $key) {
        return $value->status === 'in_progress';
    });
    if ($in_progress->count() > 0) {
        return false;
    }
    $server = Server::find($server_id);
    $concurrent_builds = $server->settings->concurrent_builds;

    if ($deployments->count() > $concurrent_builds) {
        return false;
    }

    return true;
}
function next_after_cancel(?Server $server = null)
{
    if ($server) {
        $next_found = ApplicationDeploymentQueue::where('server_id', data_get($server, 'id'))->where('status', ApplicationDeploymentStatus::QUEUED)->get()->sortBy('created_at');
        if ($next_found->count() > 0) {
            foreach ($next_found as $next) {
                $server = Server::find($next->server_id);
                $concurrent_builds = $server->settings->concurrent_builds;
                $inprogress_deployments = ApplicationDeploymentQueue::where('server_id', $next->server_id)->whereIn('status', [ApplicationDeploymentStatus::QUEUED])->get()->sortByDesc('created_at');
                if ($inprogress_deployments->count() < $concurrent_builds) {
                    $next->update([
                        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
                    ]);

                    ApplicationDeploymentJob::dispatch(
                        application_deployment_queue_id: $next->id,
                    );
                }
                break;
            }
        }
    }
}

function configValidator(string $config)
{
    $validator = Validator::make(['config' => $config], [
        'config' => 'required|json',
    ]);
    if ($validator->fails()) {
        throw new \Exception('Invalid JSON format');
    }
    $config = json_decode($config, true);
    $messages = [
        'config.coolify.project_uuid.required' => 'Project UUID is required (coolify.project_uuid) in the coolify configuration.',
        'config.coolify.environment_uuid.required' => 'Environment UUID is required (coolify.environment_uuid) in the coolify configuration.',
        'config.coolify.destination_type.required' => 'Destination type is required (coolify.destination_type) in the coolify configuration.',
        'config.coolify.destination_id.required' => 'Destination ID is required (coolify.destination_id) in the coolify configuration.',
        'config.build.build_pack.required' => 'Build pack is required (build.build_pack) in the build configuration.',
        'config.build.build_pack.in' => 'Build pack must be one of: nixpacks, static, dockerfile, or dockercompose (build.build_pack).',
        'config.source.git_repository.required_if' => 'Git repository is required (source.git_repository) when using nixpacks or static build pack.',
        'config.source.git_branch.required_if' => 'Git branch is required (source.git_branch) when using nixpacks or static build pack.',
        'config.build.docker_compose.content.required_if' => 'Docker compose content is required (build.docker_compose.content) when using dockercompose build pack.',
        'config.network.domains.redirect.in' => 'Domain redirect must be one of: www, non-www, or both (network.domains.redirect).',
        'config.settings.gpu.driver.in' => 'GPU driver must be nvidia (settings.gpu.driver).',

        'config.environment_variables.production.*.key.required' => 'Environment variable key is required (environment_variables.production[].key).',
        'config.environment_variables.production.*.value.required' => 'Environment variable value is required (environment_variables.production[].value).',
        'config.environment_variables.preview.*.key.required' => 'Preview environment variable key is required (environment_variables.preview[].key).',
        'config.environment_variables.preview.*.value.required' => 'Preview environment variable value is required (environment_variables.preview[].value).',

        'config.persistent_storages.*.mount_path.required' => 'Persistent storage mount path is required (persistent_storages[].mount_path).',
        'config.persistent_storages.*.host_path.required' => 'Persistent storage host path is required (persistent_storages[].host_path).',

        'config.scheduled_tasks.*.name.required' => 'Scheduled task name is required (scheduled_tasks[].name).',
        'config.scheduled_tasks.*.command.required' => 'Scheduled task command is required (scheduled_tasks[].command).',
        'config.scheduled_tasks.*.frequency.required' => 'Scheduled task frequency is required (scheduled_tasks[].frequency).',

        'config.name.string' => 'Name must be a string (name).',
        'config.description.string' => 'Description must be a string (description).',
        'config.coolify.array' => 'Coolify configuration must be an object (coolify).',
        'config.build.base_directory.string' => 'Base directory must be a string (build.base_directory).',
        'config.build.publish_directory.string' => 'Publish directory must be a string (build.publish_directory).',
        'config.network.ports.expose.string' => 'Exposed ports must be a string (network.ports.expose).',
        'config.settings.array' => 'Settings must be an object (settings).',
        'config.settings.deployment.array' => 'Deployment settings must be an object (settings.deployment).',
        'config.settings.git.array' => 'Git settings must be an object (settings.git).',
        'config.settings.docker.array' => 'Docker settings must be an object (settings.docker).',
        'config.settings.gpu.array' => 'GPU settings must be an object (settings.gpu).',
        'config.settings.logging.array' => 'Logging settings must be an object (settings.logging).',
        'config.settings.environment.array' => 'Environment settings must be an object (settings.environment).',
        'config.settings.proxy.array' => 'Proxy settings must be an object (settings.proxy).',
        'config.settings.type.array' => 'Type settings must be an object (settings.type).',
        'config.environment_variables.production.array' => 'Production environment variables must be an array (environment_variables.production).',
        'config.environment_variables.preview.array' => 'Preview environment variables must be an array (environment_variables.preview).',
        'config.health_check.path.string' => 'Health check path must be a string (health_check.path).',
        'config.health_check.port.string' => 'Health check port must be a string (health_check.port).',
        'config.health_check.method.string' => 'Health check method must be a string (health_check.method).',
        'config.health_check.return_code.integer' => 'Health check return code must be an integer (health_check.return_code).',
        'config.health_check.interval.integer' => 'Health check interval must be an integer (health_check.interval).',
        'config.health_check.timeout.integer' => 'Health check timeout must be an integer (health_check.timeout).',
        'config.health_check.retries.integer' => 'Health check retries must be an integer (health_check.retries).',
        'config.resources.memory.limit.string' => 'Memory limit must be a string (resources.memory.limit).',
        'config.resources.memory.swap.string' => 'Memory swap must be a string (resources.memory.swap).',
        'config.resources.memory.swappiness.integer' => 'Memory swappiness must be an integer (resources.memory.swappiness).',
        'config.resources.cpu.limit.string' => 'CPU limit must be a string (resources.cpu.limit).',
        'config.resources.cpu.shares.integer' => 'CPU shares must be an integer (resources.cpu.shares).',
        'config.network.domains.fqdn.string' => 'FQDN must be a string (network.domains.fqdn).',
        'config.deployment.pre_deployment.command.string' => 'Pre-deployment command must be a string',
        'config.deployment.pre_deployment.container.string' => 'Pre-deployment container must be a string',
        'config.deployment.post_deployment.command.string' => 'Post-deployment command must be a string',
        'config.deployment.post_deployment.container.string' => 'Post-deployment container must be a string',
        'config.webhooks.secrets.github.string' => 'Github webhook secret must be a string',
        'config.webhooks.secrets.gitlab.string' => 'Gitlab webhook secret must be a string',
        'config.webhooks.secrets.bitbucket.string' => 'Bitbucket webhook secret must be a string',
        'config.webhooks.secrets.gitea.string' => 'Gitea webhook secret must be a string',
    ];

    $deepValidator = Validator::make(['config' => $config], [
        'config.name' => 'string',
        'config.description' => 'nullable|string',
        'config.coolify' => 'required|array',
        'config.coolify.project_uuid' => 'required|string',
        'config.coolify.environment_uuid' => 'required|string',
        'config.coolify.destination_uuid' => 'required|string',

        'config.source' => 'array',
        'config.source.git_repository' => 'required_if:config.build.build_pack,nixpacks,static|string',
        'config.source.git_branch' => 'required_if:config.build.build_pack,nixpacks,static|string',
        'config.source.git_commit_sha' => 'nullable|string',
        'config.source.repository_project_id' => 'nullable|integer',

        'config.build' => 'required|array',
        'config.build.build_pack' => 'required|string|in:nixpacks,static,dockerfile,dockercompose',
        'config.build.static_image' => 'nullable|string',
        'config.build.base_directory' => 'nullable|string',
        'config.build.publish_directory' => 'nullable|string',
        'config.build.install_command' => 'nullable|string',
        'config.build.build_command' => 'nullable|string',
        'config.build.start_command' => 'nullable|string',
        'config.build.watch_paths' => 'nullable|string',

        'config.build.dockerfile' => 'array',
        // 'config.build.dockerfile.content' => 'nullable|string',
        'config.build.dockerfile.location' => 'nullable|string',
        'config.build.dockerfile.target_build' => 'nullable|string',

        'config.build.docker_compose' => 'array',
        // 'config.build.docker_compose.content' => 'required_if:config.build.build_pack,dockercompose|string',
        'config.build.docker_compose.location' => 'nullable|string',
        // 'config.build.docker_compose.parsing_version' => 'nullable|string',
        'config.build.docker_compose.domains' => 'nullable|string',
        'config.build.docker_compose.custom_start_command' => 'nullable|string',
        'config.build.docker_compose.custom_build_command' => 'nullable|string',

        'config.build.docker' => 'array',
        'config.build.docker.custom_options' => 'nullable|string',
        'config.build.docker.custom_labels' => 'nullable|string',

        'config.network' => 'array',
        'config.network.domains' => 'array',
        'config.network.domains.fqdn' => 'nullable|string',
        'config.network.domains.redirect' => 'nullable|string|in:www,non-www,both',
        'config.network.domains.custom_nginx_configuration' => 'nullable|string',
        'config.network.ports' => 'array',
        'config.network.ports.expose' => 'nullable|string',
        'config.network.ports.mappings' => 'nullable|string',

        'config.health_check' => 'array',
        'config.health_check.enabled' => 'boolean',
        'config.health_check.path' => 'nullable|string',
        'config.health_check.port' => 'nullable|string',
        'config.health_check.host' => 'nullable|string',
        'config.health_check.method' => 'nullable|string',
        'config.health_check.return_code' => 'nullable|integer',
        'config.health_check.scheme' => 'nullable|string|in:http,https',
        'config.health_check.response_text' => 'nullable|string',
        'config.health_check.interval' => 'nullable|integer',
        'config.health_check.timeout' => 'nullable|integer',
        'config.health_check.retries' => 'nullable|integer',
        'config.health_check.start_period' => 'nullable|integer',

        'config.resources' => 'array',
        'config.resources.memory' => 'array',
        'config.resources.memory.limit' => 'nullable|string',
        'config.resources.memory.swap' => 'nullable|string',
        'config.resources.memory.swappiness' => 'nullable|integer',
        'config.resources.memory.reservation' => 'nullable|string',
        'config.resources.cpu' => 'array',
        'config.resources.cpu.limit' => 'nullable|string',
        'config.resources.cpu.shares' => 'nullable|integer',
        'config.resources.cpu.set' => 'nullable|string',

        'config.preview' => 'array',
        'config.preview.url_template' => 'nullable|string',

        'config.environment_variables' => 'array',
        'config.environment_variables.production' => 'array',
        'config.environment_variables.production.*.key' => 'required|string',
        'config.environment_variables.production.*.value' => 'required|string',
        'config.environment_variables.production.*.is_required' => 'boolean',
        'config.environment_variables.production.*.is_build_time' => 'boolean',
        'config.environment_variables.production.*.is_preview' => 'boolean',
        'config.environment_variables.production.*.is_multiline' => 'boolean',

        'config.environment_variables.preview' => 'array',
        'config.environment_variables.preview.*.key' => 'required|string',
        'config.environment_variables.preview.*.value' => 'required|string',
        'config.environment_variables.preview.*.is_build_time' => 'boolean',
        'config.environment_variables.preview.*.is_preview' => 'boolean',
        'config.environment_variables.preview.*.is_multiline' => 'boolean',

        'config.settings' => 'array',
        'config.settings.deployment' => 'array',
        'config.settings.deployment.auto_deploy' => 'boolean',
        'config.settings.deployment.preview_deployments' => 'boolean',
        'config.settings.deployment.preserve_repository' => 'boolean',
        'config.settings.deployment.build_server' => 'boolean',
        'config.settings.deployment.debug_mode' => 'boolean',

        'config.settings.git' => 'array',
        'config.settings.git.lfs_enabled' => 'boolean',
        'config.settings.git.submodules_enabled' => 'boolean',

        'config.settings.docker' => 'array',
        'config.settings.docker.network' => 'array',
        'config.settings.docker.network.connect' => 'boolean',
        'config.settings.docker.network.custom_internal_name' => 'nullable|string',

        'config.settings.docker.container' => 'array',
        'config.settings.docker.container.consistent_name' => 'boolean',
        'config.settings.docker.container.label_escape' => 'boolean',
        'config.settings.docker.container.label_readonly' => 'boolean',

        'config.settings.docker.build' => 'array',
        'config.settings.docker.build.disable_cache' => 'boolean',
        'config.settings.docker.build.raw_compose_deployment' => 'boolean',

        'config.settings.gpu' => 'array',
        'config.settings.gpu.enabled' => 'boolean',
        'config.settings.gpu.driver' => 'string|in:nvidia',

        'config.settings.logging' => 'array',
        'config.settings.logging.drain_enabled' => 'boolean',
        'config.settings.logging.include_timestamps' => 'boolean',

        'config.settings.environment' => 'array',
        'config.settings.environment.sort_variables' => 'boolean',

        'config.settings.proxy' => 'array',
        'config.settings.proxy.force_https' => 'boolean',
        'config.settings.proxy.gzip' => 'boolean',
        'config.settings.proxy.stripprefix' => 'boolean',

        'config.settings.type' => 'array',
        'config.settings.type.static' => 'boolean',

        'config.persistent_storages' => 'array',
        'config.persistent_storages.*.mount_path' => 'required|string',
        'config.persistent_storages.*.host_path' => 'string',

        'config.scheduled_tasks' => 'array',
        'config.scheduled_tasks.*.enabled' => 'boolean',
        'config.scheduled_tasks.*.name' => 'required|string',
        'config.scheduled_tasks.*.command' => 'required|string',
        'config.scheduled_tasks.*.frequency' => 'required|string',

        'config.tags' => 'array',
        'config.tags.*' => 'string',

        'config.deployment' => 'array',
        'config.deployment.pre_deployment' => 'array',
        'config.deployment.pre_deployment.command' => 'nullable|string',
        'config.deployment.pre_deployment.container' => 'nullable|string',
        'config.deployment.post_deployment' => 'array',
        'config.deployment.post_deployment.command' => 'nullable|string',
        'config.deployment.post_deployment.container' => 'nullable|string',

        'config.webhooks' => 'array',
        'config.webhooks.secrets' => 'array',
        'config.webhooks.secrets.github' => 'nullable|string',
        'config.webhooks.secrets.gitlab' => 'nullable|string',
        'config.webhooks.secrets.bitbucket' => 'nullable|string',
        'config.webhooks.secrets.gitea' => 'nullable|string',

        'config.docker_custom_options' => 'nullable|string',
        'config.labels' => 'nullable|array',
        'config.labels.*' => 'string',
    ], $messages);

    if ($deepValidator->fails()) {
        $errors = $deepValidator->errors()->all();
        throw new \Exception('Validation failed:'.PHP_EOL.'- '.implode(PHP_EOL.'- ', $errors));
    }

    // Custom validation for dockerfile content
    // $buildPack = data_get($config, 'build.build_pack');
    // $gitRepository = data_get($config, 'source.git_repository');
    // $gitBranch = data_get($config, 'source.git_branch');
    // $dockerfileContent = data_get($config, 'build.dockerfile.content');

    // if ($buildPack === 'dockerfile' && ! $gitRepository && ! $gitBranch) {
    //     throw new \Exception('Validation failed:'.PHP_EOL.PHP_EOL.'- When using dockerfile build pack without git repository and branch, the Dockerfile content is required (build.dockerfile.content)');
    // }

    // validate domains
    $domains = data_get($config, 'network.domains.fqdn');
    if ($domains) {
        $domains = explode(',', $domains);
        foreach ($domains as $domain) {
            if (! str_starts_with($domain, 'http://') && ! str_starts_with($domain, 'https://')) {
                throw new \Exception('Validation failed:'.PHP_EOL.PHP_EOL.'- Domain must start with http:// or https://<br><br>Your domain: '.$domain);
            }
            $parsedDomain = parse_url($domain, PHP_URL_HOST);
            if (! $parsedDomain || ! filter_var($parsedDomain, FILTER_VALIDATE_DOMAIN)) {
                throw new \Exception('Validation failed:'.PHP_EOL.PHP_EOL.'- Invalid domain: '.$domain);
            }
        }
    }

    return $config;
}
