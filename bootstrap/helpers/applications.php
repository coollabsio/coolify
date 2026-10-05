<?php

use App\Actions\Application\StopApplication;
use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\VolumeCloneJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Notifications\Application\DeploymentFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Url\Url;

function queue_application_deployment(Application $application, string $deployment_uuid, ?int $pull_request_id = 0, ?string $commit = null, bool $force_rebuild = false, bool $is_webhook = false, bool $is_api = false, bool $restart_only = false, ?string $git_type = null, bool $no_questions_asked = false, ?Server $server = null, ?StandaloneDocker $destination = null, bool $only_this_server = false, bool $rollback = false, ?string $docker_registry_image_tag = null, ?string $parent_deployment_uuid = null)
{
    $commit = $commit ?: ($application->git_commit_sha ?: 'HEAD');
    $commit = validateGitRef($commit, 'deployment commit');
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

    $admission = DB::transaction(function () use ($application, $application_id, $commit, $deployment_uuid, $deployment_url, $destination_id, $docker_registry_image_tag, $force_rebuild, $git_type, $is_api, $is_webhook, $no_questions_asked, $only_this_server, $parent_deployment_uuid, $pull_request_id, $restart_only, $rollback, $server_id, $server_name) {
        // Lock stable rows because an empty deployment queue has no row to lock.
        Application::query()->whereKey($application_id)->lockForUpdate()->firstOrFail();
        $serverForQueueCheck = Server::query()->whereKey($server_id)->lockForUpdate()->firstOrFail();
        $queue_limit = $serverForQueueCheck->settings->deployment_queue_limit ?? 25;
        $queued_count = ApplicationDeploymentQueue::where('server_id', $server_id)
            ->where('status', ApplicationDeploymentStatus::QUEUED->value)
            ->count();

        if ($queued_count >= $queue_limit) {
            return [
                'status' => 'queue_full',
                'message' => 'Deployment queue is full. Please wait for existing deployments to complete.',
            ];
        }

        $existing_deployment = ApplicationDeploymentQueue::where('application_id', $application_id)
            ->where('commit', $commit)
            ->where('pull_request_id', $pull_request_id)
            ->where('docker_registry_image_tag', $docker_registry_image_tag)
            ->whereIn('status', [ApplicationDeploymentStatus::IN_PROGRESS->value, ApplicationDeploymentStatus::QUEUED->value])
            ->first();

        if ($existing_deployment && ! $force_rebuild && ! $rollback && ! $no_questions_asked) {
            return [
                'status' => 'skipped',
                'message' => 'Deployment already queued for this commit.',
                'deployment_uuid' => $existing_deployment->deployment_uuid,
                'existing_deployment' => $existing_deployment,
            ];
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
            'docker_registry_image_tag' => $docker_registry_image_tag,
            'force_rebuild' => $force_rebuild,
            'is_webhook' => $is_webhook,
            'is_api' => $is_api,
            'restart_only' => $restart_only,
            'commit' => $commit,
            'rollback' => $rollback,
            'git_type' => $git_type,
            'only_this_server' => $only_this_server,
            'parent_deployment_uuid' => $parent_deployment_uuid,
        ]);

        // Decide whether to start while the application and server rows are still locked,
        // so concurrent requests cannot both see an idle queue and start two deployments.
        $started = $no_questions_asked || next_queuable($server_id, $application_id, $commit, $pull_request_id);
        if ($started) {
            $deployment->update([
                'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
            ]);
        }

        return ['deployment' => $deployment, 'started' => $started];
    });

    if (! isset($admission['deployment'])) {
        return $admission;
    }

    $deployment = $admission['deployment'];

    if (auth()->check() && ! $is_webhook && ! $is_api && ! $rollback) {
        auditLog($restart_only ? 'ui.application.restarted' : 'ui.application.deployed', [
            'application_uuid' => $application->uuid,
            'application_name' => $application->name,
            'deployment_uuid' => $deployment_uuid,
            'force_rebuild' => $force_rebuild,
        ]);
    }

    if ($admission['started']) {
        try {
            ApplicationDeploymentJob::dispatch(
                application_deployment_queue_id: $deployment->id,
            );
        } catch (Throwable $exception) {
            fail_undispatchable_deployment($deployment, $exception);

            throw $exception;
        }
    }

    return [
        'status' => 'queued',
        'message' => 'Deployment queued.',
        'deployment_uuid' => $deployment_uuid,
    ];
}
/**
 * Start a queued deployment right away, ignoring the per-application and per-server
 * concurrency limits. It returns false without dispatching when the deployment is no
 * longer queued, so a repeated force start never runs the same deployment twice.
 */
function force_start_deployment(ApplicationDeploymentQueue $deployment): bool
{
    return start_queued_deployment($deployment, force: true);
}
/**
 * Start the queued deployments that can run now on the application's primary server, on the
 * server of the deployment that just ended (an additional server), and on every other server
 * with a queued deployment of this application, because those waited for this one to end.
 */
function queue_next_deployment(Application $application, ?int $finished_deployment_server_id = null)
{
    $application_queued_server_ids = ApplicationDeploymentQueue::where('application_id', $application->id)
        ->where('status', ApplicationDeploymentStatus::QUEUED)
        ->distinct()
        ->pluck('server_id');
    $server_ids = collect([$application->destination?->server_id, $finished_deployment_server_id])
        ->merge($application_queued_server_ids)
        ->filter(fn ($server_id) => $server_id !== null)
        ->unique()
        ->values();
    $queued_deployments = ApplicationDeploymentQueue::whereIn('server_id', $server_ids)
        ->where('status', ApplicationDeploymentStatus::QUEUED)
        ->get()
        ->sortBy('created_at');

    foreach ($queued_deployments as $next_deployment) {
        try {
            start_queued_deployment($next_deployment);
        } catch (Throwable $e) {
            Log::warning("Failed to start queued deployment {$next_deployment->deployment_uuid}: {$e->getMessage()}");
        }
    }
}

/**
 * Start a queued deployment if it can run now. The check and the status change run under
 * the same application and server row locks as queue admission, so two workers cannot both
 * start the same deployment or exceed the per-application and per-server limits.
 * With $force, the limits are skipped but the deployment still has to be queued.
 */
function start_queued_deployment(ApplicationDeploymentQueue $deployment, bool $force = false): bool
{
    $started = DB::transaction(function () use ($deployment, $force): bool {
        Application::query()->whereKey($deployment->application_id)->lockForUpdate()->first();
        Server::query()->whereKey($deployment->server_id)->lockForUpdate()->first();
        $current = ApplicationDeploymentQueue::query()->whereKey($deployment->id)->lockForUpdate()->first();

        if (! $current || $current->status !== ApplicationDeploymentStatus::QUEUED->value) {
            return false;
        }

        if (! $force && ! next_queuable($current->server_id, $current->application_id, $current->commit, $current->pull_request_id)) {
            return false;
        }

        $current->update([
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);

        return true;
    });

    if (! $started) {
        return false;
    }

    try {
        ApplicationDeploymentJob::dispatch(
            application_deployment_queue_id: $deployment->id,
        );
    } catch (Throwable $exception) {
        fail_undispatchable_deployment($deployment, $exception);

        return false;
    }

    return true;
}

/**
 * Fail a deployment whose job could not be created or queued, for example a deployment queued
 * before an upgrade with a commit that no longer validates. Leaving it in progress would block
 * every later deployment of the application and stop the queue advancement loop.
 */
function fail_undispatchable_deployment(ApplicationDeploymentQueue $deployment, Throwable $exception): void
{
    $failed = ApplicationDeploymentQueue::query()
        ->whereKey($deployment->id)
        ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->update(['status' => ApplicationDeploymentStatus::FAILED->value]);

    if ($failed === 0) {
        return;
    }

    Log::warning("Deployment {$deployment->deployment_uuid} could not be started: {$exception->getMessage()}");

    $deployment->refresh();
    $deployment->addLogEntry('========================================', 'stderr');
    $deployment->addLogEntry("Deployment could not be started: {$exception->getMessage()}", 'stderr');
    $deployment->addLogEntry('========================================', 'stderr');

    try {
        $application = Application::query()->find($deployment->application_id);
        if (! $application || filled($deployment->parent_deployment_uuid)) {
            return;
        }
        $preview = $deployment->pull_request_id !== 0
            ? ApplicationPreview::findPreviewByApplicationAndPullId($application->id, $deployment->pull_request_id)
            : null;
        $application->environment?->project?->team?->notify(new DeploymentFailed($application, $deployment->deployment_uuid, $preview));
    } catch (Throwable $notificationException) {
        Log::warning("Failed to send the failure notification for deployment {$deployment->deployment_uuid}: {$notificationException->getMessage()}");
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
    // A deleted server keeps its queued deployments; they cannot run anymore.
    $server = Server::find($server_id);
    if (! $server) {
        return false;
    }
    $concurrent_builds = $server->settings->concurrent_builds;
    $active_deployments = ApplicationDeploymentQueue::where('server_id', $server_id)
        ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->count();

    if ($active_deployments >= $concurrent_builds) {
        return false;
    }

    return true;
}
/**
 * Start the queued deployments that can run after a cancellation. With the application of the
 * cancelled deployment, its queued deployments on all servers start too, as when a deployment ends.
 */
function next_after_cancel(?Server $server = null, ?Application $application = null)
{
    if ($application) {
        queue_next_deployment($application, $server?->id);

        return;
    }
    if ($server) {
        $next_found = ApplicationDeploymentQueue::where('server_id', data_get($server, 'id'))
            ->where('status', ApplicationDeploymentStatus::QUEUED)
            ->get()
            ->sortBy('created_at');

        foreach ($next_found as $next) {
            start_queued_deployment($next);
        }
    }
}

function clone_application(Application $source, $destination, array $overrides = [], bool $cloneVolumeData = false): Application
{
    $uuid = $overrides['uuid'] ?? new_public_id();
    $server = $destination->server;

    $teamId = $server->team_id;
    $sourceTeamId = $source->team()?->id;
    $environmentTeamId = Environment::query()->find($overrides['environment_id'] ?? $source->environment_id)?->project?->team_id;
    if ($sourceTeamId === null || $environmentTeamId === null || (int) $sourceTeamId !== (int) $teamId || (int) $environmentTeamId !== (int) $teamId) {
        throw new RuntimeException('The application, the target environment, and the destination must belong to the same team.');
    }

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
        'destination_type' => $destination->getMorphClass(),
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
        $newApplication->setRelation('settings', $newApplicationSettings->fresh());
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
            'uuid' => new_public_id(),
            'application_id' => $newApplication->id,
            'team_id' => $teamId,
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
            'uuid' => new_public_id(),
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
        if ($volume->standalone_sqlite_id !== null) {
            // A mounted SQLite database volume stays connected to the source only; the clone gets its own volume.
            $newName = $newApplication->uuid.'-'.$volume->name;
        } elseif (str_starts_with($volume->name, $source->uuid)) {
            $newName = str($volume->name)->replace($source->uuid, $newApplication->uuid);
        } else {
            $newName = $newApplication->uuid.'-'.str($volume->name)->afterLast('-');
        }

        $newPersistentVolume = $volume->replicate([
            'id',
            'created_at',
            'updated_at',
            'uuid',
        ])->fill([
            'name' => $newName,
            'resource_id' => $newApplication->id,
            'standalone_sqlite_id' => null,
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
                    deployment_uuid: new_public_id(),
                    application: $source,
                    server: $sourceServer,
                    destination: $source->destination,
                    no_questions_asked: true
                );
            } catch (Exception $e) {
                Log::error('Failed to copy volume data for '.$volume->name.': '.$e->getMessage());
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
        EnvironmentVariable::withoutEvents(function () use ($environmentVariable, $newApplication) {
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

    $source->cloneSecretManagerLinkTo($newApplication);

    // Clone preview environment variables
    $previewEnvironmentVariables = $source->environment_variables_preview()->get();
    foreach ($previewEnvironmentVariables as $previewEnvironmentVariable) {
        EnvironmentVariable::withoutEvents(function () use ($previewEnvironmentVariable, $newApplication) {
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
