<?php

use App\Actions\Application\StopApplication;
use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\VolumeCloneJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Spatie\Url\Url;
use Visus\Cuid2\Cuid2;

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

    // Check if there's already a deployment in progress or queued for this application and commit
    $existing_deployment = ApplicationDeploymentQueue::where('application_id', $application_id)
        ->where('commit', $commit)
        ->where('pull_request_id', $pull_request_id)
        ->whereIn('status', [ApplicationDeploymentStatus::IN_PROGRESS->value, ApplicationDeploymentStatus::QUEUED->value])
        ->first();

    if ($existing_deployment) {
        // If force_rebuild is true or rollback is true or no_questions_asked is true, we'll still create a new deployment
        if (! $force_rebuild && ! $rollback && ! $no_questions_asked) {
            // Return the existing deployment's details
            return [
                'status' => 'skipped',
                'message' => 'Deployment already queued for this commit.',
                'deployment_uuid' => $existing_deployment->deployment_uuid,
                'existing_deployment' => $existing_deployment,
            ];
        }
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
    } elseif (next_queuable($server_id, $application_id, $commit, $pull_request_id)) {
        ApplicationDeploymentJob::dispatch(
            application_deployment_queue_id: $deployment->id,
        );
    }

    return [
        'status' => 'queued',
        'message' => 'Deployment queued.',
        'deployment_uuid' => $deployment_uuid,
    ];
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
    $queued_deployments = ApplicationDeploymentQueue::where('server_id', $server_id)
        ->where('status', ApplicationDeploymentStatus::QUEUED)
        ->get()
        ->sortBy('created_at');

    foreach ($queued_deployments as $next_deployment) {
        // Check if this queued deployment can actually run
        if (next_queuable($next_deployment->server_id, $next_deployment->application_id, $next_deployment->commit, $next_deployment->pull_request_id)) {
            $next_deployment->update([
                'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
            ]);

            ApplicationDeploymentJob::dispatch(
                application_deployment_queue_id: $next_deployment->id,
            );
        }
    }
}

function next_queuable(string $server_id, string $application_id, string $commit = 'HEAD', int $pull_request_id = 0): bool
{
    // Check if there's already a deployment in progress for this application with the same pull_request_id
    // This allows normal deployments and PR deployments to run concurrently
    $in_progress = ApplicationDeploymentQueue::where('application_id', $application_id)
        ->where('pull_request_id', $pull_request_id)
        ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->exists();

    if ($in_progress) {
        return false;
    }

    // Check server's concurrent build limit
    $server = Server::find($server_id);
    $concurrent_builds = $server->settings->concurrent_builds;
    $active_deployments = ApplicationDeploymentQueue::where('server_id', $server_id)
        ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->count();

    if ($active_deployments >= $concurrent_builds) {
        return false;
    }

    return true;
}
function next_after_cancel(?Server $server = null)
{
    if ($server) {
        $next_found = ApplicationDeploymentQueue::where('server_id', data_get($server, 'id'))
            ->where('status', ApplicationDeploymentStatus::QUEUED)
            ->get()
            ->sortBy('created_at');

        if ($next_found->count() > 0) {
            foreach ($next_found as $next) {
                // Use next_queuable to properly check if this deployment can run
                if (next_queuable($next->server_id, $next->application_id, $next->commit, $next->pull_request_id)) {
                    $next->update([
                        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
                    ]);

                    ApplicationDeploymentJob::dispatch(
                        application_deployment_queue_id: $next->id,
                    );
                }
            }
        }
    }
}

function clone_application(Application $source, $destination, array $overrides = [], bool $cloneVolumeData = false): Application
{
    $uuid = $overrides['uuid'] ?? (string) new Cuid2;
    $server = $destination->server;

    // Prepare name and URL
    $name = $overrides['name'] ?? 'clone-of-'.str($source->name)->limit(20).'-'.$uuid;
    $applicationSettings = $source->settings;
    $url = $overrides['fqdn'] ?? $source->fqdn;

    if ($server->proxyType() !== 'NONE' && $applicationSettings->is_container_label_readonly_enabled === true) {
        $url = generateUrl(server: $server, random: $uuid);
    }

    // Clone the application
    $newApplication = $source->replicate([
        'id',
        'created_at',
        'updated_at',
        'additional_servers_count',
        'additional_networks_count',
    ])->fill(array_merge([
        'uuid' => $uuid,
        'name' => $name,
        'fqdn' => $url,
        'status' => 'exited',
        'destination_id' => $destination->id,
    ], $overrides));
    $newApplication->save();

    // Update custom labels if needed
    if ($newApplication->destination->server->proxyType() !== 'NONE' && $applicationSettings->is_container_label_readonly_enabled === true) {
        $customLabels = str(implode('|coolify|', generateLabelsApplication($newApplication)))->replace('|coolify|', "\n");
        $newApplication->custom_labels = base64_encode($customLabels);
        $newApplication->save();
    }

    // Clone settings
    $newApplication->settings()->delete();
    if ($applicationSettings) {
        $newApplicationSettings = $applicationSettings->replicate([
            'id',
            'created_at',
            'updated_at',
        ])->fill([
            'application_id' => $newApplication->id,
        ]);
        $newApplicationSettings->save();
    }

    // Clone tags
    $tags = $source->tags;
    foreach ($tags as $tag) {
        $newApplication->tags()->attach($tag->id);
    }

    // Clone scheduled tasks
    $scheduledTasks = $source->scheduled_tasks()->get();
    foreach ($scheduledTasks as $task) {
        $newTask = $task->replicate([
            'id',
            'created_at',
            'updated_at',
        ])->fill([
            'uuid' => (string) new Cuid2,
            'application_id' => $newApplication->id,
            'team_id' => currentTeam()->id,
        ]);
        $newTask->save();
    }

    // Clone previews with FQDN regeneration
    $applicationPreviews = $source->previews()->get();
    foreach ($applicationPreviews as $preview) {
        $newPreview = $preview->replicate([
            'id',
            'created_at',
            'updated_at',
        ])->fill([
            'uuid' => (string) new Cuid2,
            'application_id' => $newApplication->id,
            'status' => 'exited',
            'fqdn' => null,
            'docker_compose_domains' => null,
        ]);
        $newPreview->save();

        // Regenerate FQDN for the cloned preview
        if ($newApplication->build_pack === 'dockercompose') {
            $newPreview->generate_preview_fqdn_compose();
        } else {
            $newPreview->generate_preview_fqdn();
        }
    }

    // Clone persistent volumes
    $persistentVolumes = $source->persistentStorages()->get();
    foreach ($persistentVolumes as $volume) {
        $newName = '';
        if (str_starts_with($volume->name, $source->uuid)) {
            $newName = str($volume->name)->replace($source->uuid, $newApplication->uuid);
        } else {
            $newName = $newApplication->uuid.'-'.str($volume->name)->afterLast('-');
        }

        $newPersistentVolume = $volume->replicate([
            'id',
            'created_at',
            'updated_at',
        ])->fill([
            'name' => $newName,
            'resource_id' => $newApplication->id,
        ]);
        $newPersistentVolume->save();

        if ($cloneVolumeData) {
            try {
                StopApplication::dispatch($source, false, false);
                $sourceVolume = $volume->name;
                $targetVolume = $newPersistentVolume->name;
                $sourceServer = $source->destination->server;
                $targetServer = $newApplication->destination->server;

                VolumeCloneJob::dispatch($sourceVolume, $targetVolume, $sourceServer, $targetServer, $newPersistentVolume);

                queue_application_deployment(
                    deployment_uuid: (string) new Cuid2,
                    application: $source,
                    server: $sourceServer,
                    destination: $source->destination,
                    no_questions_asked: true
                );
            } catch (\Exception $e) {
                \Log::error('Failed to copy volume data for '.$volume->name.': '.$e->getMessage());
            }
        }
    }

    // Clone file storages
    $fileStorages = $source->fileStorages()->get();
    foreach ($fileStorages as $storage) {
        $newStorage = $storage->replicate([
            'id',
            'created_at',
            'updated_at',
        ])->fill([
            'resource_id' => $newApplication->id,
        ]);
        $newStorage->save();
    }

    // Clone production environment variables without triggering the created hook
    $environmentVariables = $source->environment_variables()->get();
    foreach ($environmentVariables as $environmentVariable) {
        \App\Models\EnvironmentVariable::withoutEvents(function () use ($environmentVariable, $newApplication) {
            $newEnvironmentVariable = $environmentVariable->replicate([
                'id',
                'created_at',
                'updated_at',
            ])->fill([
                'resourceable_id' => $newApplication->id,
                'resourceable_type' => $newApplication->getMorphClass(),
                'is_preview' => false,
            ]);
            $newEnvironmentVariable->save();
        });
    }

    // Clone preview environment variables
    $previewEnvironmentVariables = $source->environment_variables_preview()->get();
    foreach ($previewEnvironmentVariables as $previewEnvironmentVariable) {
        \App\Models\EnvironmentVariable::withoutEvents(function () use ($previewEnvironmentVariable, $newApplication) {
            $newPreviewEnvironmentVariable = $previewEnvironmentVariable->replicate([
                'id',
                'created_at',
                'updated_at',
            ])->fill([
                'resourceable_id' => $newApplication->id,
                'resourceable_type' => $newApplication->getMorphClass(),
                'is_preview' => true,
            ]);
            $newPreviewEnvironmentVariable->save();
        });
    }

    return $newApplication;
}
