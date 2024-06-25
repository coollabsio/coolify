<?php

namespace App\Http\Controllers\Api;

use App\Actions\Application\StopApplication;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Visus\Cuid2\Cuid2;

class Applications extends Controller
{
    public function applications(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $projects = Project::where('team_id', $teamId)->get();
        $applications = collect();
        $applications->push($projects->pluck('applications')->flatten());
        $applications = $applications->flatten();

        return response()->json($applications);
    }

    public function application_by_uuid(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $uuid = $request->route('uuid');
        if (! $uuid) {
            return response()->json(['error' => 'UUID is required.'], 400);
        }
        $application = Application::where('uuid', $uuid)->first();
        if (! $application) {
            return response()->json(['error' => 'Application not found.'], 404);
        }

        return response()->json($application);
    }

    public function update_by_uuid(Request $request)
    {
        ray()->clearAll();
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }

        if ($request->collect()->count() == 0) {
            return response()->json([
                'message' => 'Invalid request.',
            ], 400);
        }
        $application = Application::where('uuid', $request->uuid)->first();

        if (! $application) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found',
            ], 404);
        }
        $server = $application->destination->server;
        $allowedFields = ['name', 'description', 'domains', 'git_repository', 'git_branch', 'git_commit_sha', 'docker_registry_image_name', 'docker_registry_image_tag', 'build_pack', 'static_image', 'install_command', 'build_command', 'start_command', 'ports_exposes', 'ports_mappings', 'base_directory', 'publish_directory', 'health_check_enabled', 'health_check_path', 'health_check_port', 'health_check_host', 'health_check_method', 'health_check_return_code', 'health_check_scheme', 'health_check_response_text', 'health_check_interval', 'health_check_timeout', 'health_check_retries', 'health_check_start_period', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'custom_labels', 'custom_docker_run_options', 'post_deployment_command', 'post_deployment_command_container', 'pre_deployment_command', 'pre_deployment_command_container', 'watch_paths', 'manual_webhook_secret_github', 'manual_webhook_secret_gitlab', 'manual_webhook_secret_bitbucket', 'manual_webhook_secret_gitea', 'docker_compose_location', 'docker_compose', 'docker_compose_raw', 'docker_compose_domains', 'docker_compose_custom_start_command', 'docker_compose_custom_build_command', 'redirect'];
        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'description' => 'string|nullable',
            'domains' => 'string',
            'git_repository' => 'string',
            'git_branch' => 'string',
            'git_commit_sha' => 'string',
            'docker_registry_image_name' => 'string|nullable',
            'docker_registry_image_tag' => 'string|nullable',
            'build_pack' => 'string',
            'static_image' => 'string',
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
            'watch_paths' => 'string|nullable',
            'manual_webhook_secret_github' => 'string|nullable',
            'manual_webhook_secret_gitlab' => 'string|nullable',
            'manual_webhook_secret_bitbucket' => 'string|nullable',
            'manual_webhook_secret_gitea' => 'string|nullable',
            'docker_compose_location' => 'string',
            'docker_compose' => 'string|nullable',
            'docker_compose_raw' => 'string|nullable',
            'docker_compose_domains' => 'string|nullable', // must be like: "{\"api\":{\"domain\":\"http:\\/\\/b8sos8k.127.0.0.1.sslip.io\"}}"
            'docker_compose_custom_start_command' => 'string|nullable',
            'docker_compose_custom_build_command' => 'string|nullable',
            'redirect' => 'enum:both,www,non-www',
        ]);

        // Validate ports_exposes
        if ($request->has('ports_exposes')) {
            $ports = explode(',', $request->ports_exposes);
            foreach ($ports as $port) {
                if (! is_numeric($port)) {
                    return response()->json([
                        'message' => 'Validation failed',
                        'errors' => [
                            'ports_exposes' => 'The ports_exposes should be a comma separated list of numbers.',
                        ],
                    ], 422);
                }
            }
        }
        // Validate ports_mappings
        if ($request->has('ports_mappings')) {
            $ports = [];
            foreach (explode(',', $request->ports_mappings) as $portMapping) {
                $port = explode(':', $portMapping);
                if (in_array($port[0], $ports)) {
                    return response()->json([
                        'message' => 'Validation failed',
                        'errors' => [
                            'ports_mappings' => 'The first number before : should be unique between mappings.',
                        ],
                    ], 422);
                }
                $ports[] = $port[0];
            }
        }
        // Validate custom_labels
        if ($request->has('custom_labels')) {
            if (! isBase64Encoded($request->custom_labels)) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => [
                        'custom_labels' => 'The custom_labels should be base64 encoded.',
                    ],
                ], 422);
            }
            $customLabels = base64_decode($request->custom_labels);
            if (mb_detect_encoding($customLabels, 'ASCII', true) === false) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => [
                        'custom_labels' => 'The custom_labels should be base64 encoded.',
                    ],
                ], 422);

            }
        }
        $extraFields = array_diff(array_keys($request->all()), $allowedFields);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            if (! empty($extraFields)) {
                foreach ($extraFields as $field) {
                    $errors->add($field, 'This field is not allowed.');
                }
            }

            return response()->json([
                'message' => 'Validation failed',
                'errors' => $errors,
            ], 422);
        }
        if ($request->has('domains') && $server->isProxyShouldRun()) {
            $fqdn = $request->domains;
            $fqdn = str($fqdn)->replaceEnd(',', '')->trim();
            $fqdn = str($fqdn)->replaceStart(',', '')->trim();
            $errors = [];
            $fqdn = str($fqdn)->trim()->explode(',')->map(function ($domain) use (&$errors) {
                if (filter_var($domain, FILTER_VALIDATE_URL) === false) {
                    $errors[] = 'Invalid domain: '.$domain;
                }

                return str($domain)->trim()->lower();
            });
            if (count($errors) > 0) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $errors,
                ], 422);
            }
            $fqdn = $fqdn->unique()->implode(',');
            $application->fqdn = $fqdn;
            $customLabels = str(implode('|coolify|', generateLabelsApplication($application)))->replace('|coolify|', "\n");
            $application->custom_labels = base64_encode($customLabels);
            $request->offsetUnset('domains');
        }
        $application->fill($request->all());
        $application->save();

        return response()->json([
            'message' => 'Application updated successfully.',
            'application' => $application,
        ]);
    }

    public function action_deploy(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $force = $request->query->get('force') ?? false;
        $instant_deploy = $request->query->get('instant_deploy') ?? false;
        $uuid = $request->route('uuid');
        if (! $uuid) {
            return response()->json(['error' => 'UUID is required.'], 400);
        }
        $application = Application::where('uuid', $uuid)->first();
        if (! $application) {
            return response()->json(['error' => 'Application not found.'], 404);
        }

        $deployment_uuid = new Cuid2(7);

        queue_application_deployment(
            application: $application,
            deployment_uuid: $deployment_uuid,
            force_rebuild: $force,
            is_api: true,
            no_questions_asked: $instant_deploy
        );

        return response()->json(
            [
                'message' => 'Deployment request queued.',
                'deployment_uuid' => $deployment_uuid->toString(),
                'deployment_api_url' => base_url().'/api/v1/deployment/'.$deployment_uuid->toString(),
            ],
            200
        );
    }

    public function action_stop(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $uuid = $request->route('uuid');
        $sync = $request->query->get('sync') ?? false;
        if (! $uuid) {
            return response()->json(['error' => 'UUID is required.'], 400);
        }
        $application = Application::where('uuid', $uuid)->first();
        if (! $application) {
            return response()->json(['error' => 'Application not found.'], 404);
        }
        if ($sync) {
            StopApplication::run($application);

            return response()->json(['message' => 'Stopped the application.'], 200);
        } else {
            StopApplication::dispatch($application);

            return response()->json(['message' => 'Stopping request queued.'], 200);
        }
    }

    public function action_restart(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $uuid = $request->route('uuid');
        if (! $uuid) {
            return response()->json(['error' => 'UUID is required.'], 400);
        }
        $application = Application::where('uuid', $uuid)->first();
        if (! $application) {
            return response()->json(['error' => 'Application not found.'], 404);
        }

        $deployment_uuid = new Cuid2(7);

        queue_application_deployment(
            application: $application,
            deployment_uuid: $deployment_uuid,
            restart_only: true,
            is_api: true,
        );

        return response()->json(
            [
                'message' => 'Restart request queued.',
                'deployment_uuid' => $deployment_uuid->toString(),
                'deployment_api_url' => base_url().'/api/v1/deployment/'.$deployment_uuid->toString(),
            ],
            200
        );

    }
}
