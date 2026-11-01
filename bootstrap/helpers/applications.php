<?php

use App\Actions\Shared\CloneVolumeDataWithSourceStopped;
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
 *
 * A deployment that fails to dispatch advances the queue again. A call made while the queue
 * is already advancing is run after the current pass instead of recursing. Each such call
 * needs a deployment that moved from queued to failed, so the loop ends.
 */
function queue_next_deployment(Application $application, ?int $finished_deployment_server_id = null)
{
    static $advancing = false;
    static $pending = [];

    $pending[] = [$application, $finished_deployment_server_id];
    if ($advancing) {
        return;
    }

    $advancing = true;
    try {
        while ($next = array_shift($pending)) {
            start_next_queued_deployments(...$next);
        }
    } finally {
        $advancing = false;
        $pending = [];
    }
}

function start_next_queued_deployments(Application $application, ?int $finished_deployment_server_id = null): void
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

    $application = Application::query()->find($deployment->application_id);
    if (! $application) {
        return;
    }

    try {
        if (blank($deployment->parent_deployment_uuid)) {
            $preview = $deployment->pull_request_id !== 0
                ? ApplicationPreview::findPreviewByApplicationAndPullId($application->id, $deployment->pull_request_id)
                : null;
            $application->environment?->project?->team?->notify(new DeploymentFailed($application, $deployment->deployment_uuid, $preview));
        }
    } catch (Throwable $notificationException) {
        Log::warning("Failed to send the failure notification for deployment {$deployment->deployment_uuid}: {$notificationException->getMessage()}");
    }

    // The failed deployment no longer holds its build slot, so the deployments it blocked can start.
    try {
        queue_next_deployment($application, $deployment->server_id);
    } catch (Throwable $queueException) {
        Log::warning("Starting the next queued deployment after {$deployment->deployment_uuid} failed: {$queueException->getMessage()}");
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
    $pendingVolumeClones = [];
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
            $pendingVolumeClones[] = new VolumeCloneJob(
                $volume->name,
                $newPersistentVolume->name,
                $source->destination->server,
                $newApplication->destination->server,
                $newPersistentVolume,
            );
        }
    }

    CloneVolumeDataWithSourceStopped::run($source, $pendingVolumeClones);

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
    $previewEnvironmentVariables = $source->environment_variables_preview()->get();

    // Drop defaults auto-created for the new application (e.g. NIXPACKS_NODE_VERSION) so the source values win
    EnvironmentVariable::query()
        ->where('resourceable_type', $newApplication->getMorphClass())
        ->where('resourceable_id', $newApplication->id)
        ->whereIn('key', $environmentVariables->pluck('key')->merge($previewEnvironmentVariables->pluck('key'))->unique())
        ->delete();

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

/**
 * The published SSH host keys of the large git hosts. Git over SSH to these hosts accepts only these keys.
 * Update this list when a provider changes its host keys.
 *
 * @see https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/githubs-ssh-key-fingerprints
 * @see https://api.github.com/meta (ssh_keys)
 * @see https://docs.gitlab.com/user/gitlab_com/#ssh-known_hosts-entries
 * @see https://bitbucket.org/site/ssh
 */
const GIT_PINNED_HOST_KEYS = [
    'github.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl',
    'github.com ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBEmKSENjQEezOmxkZMy7opKgwFB9nkt5YRrYMjNuG5N87uRgg6CLrbo5wAdT/y6v0mKV0U2w0WZ2YB/++Tpockg=',
    'github.com ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABgQCj7ndNxQowgcQnjshcLrqPEiiphnt+VTTvDP6mHBL9j1aNUkY4Ue1gvwnGLVlOhGeYrnZaMgRK6+PKCUXaDbC7qtbW8gIkhL7aGCsOr/C56SJMy/BCZfxd1nWzAOxSDPgVsmerOBYfNqltV9/hWCqBywINIR+5dIg6JTJ72pcEpEjcYgXkE2YEFXV1JHnsKgbLWNlhScqb2UmyRkQyytRLtL+38TGxkxCflmO+5Z8CSSNY7GidjMIZ7Q4zMjA2n1nGrlTDkzwDCsw+wqFPGQA179cnfGWOWRVruj16z6XyvxvjJwbz0wQZ75XK5tKSb7FNyeIEs4TT4jk+S4dhPeAUC5y+bDYirYgM4GC7uEnztnZyaVWQ7B381AK4Qdrwt51ZqExKbQpTUNn+EjqoTwvqNj4kqx5QUCI0ThS/YkOxJCXmPUWZbhjpCg56i+2aB6CmK2JGhn57K5mj0MNdBXA4/WnwH6XoPWJzK5Nyu2zB3nAZp+S5hpQs+p1vN1/wsjk=',
    'gitlab.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAfuCHKVTjquxvt6CM6tdG4SLp1Btn/nOeHHE5UOzRdf',
    'gitlab.com ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBFSMqzJeV9rUzU4kWitGjeR4PWSa29SPqJ1fVkhtj3Hw9xjLVXVYrU9QlYWrOLXBpQ6KWjbjTDTdDkoohFzgbEY=',
    'gitlab.com ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQCsj2bNKTBSpIYDEGk9KxsGh3mySTRgMtXL583qmBpzeQ+jqCMRgBqB98u3z++J1sKlXHWfM9dyhSevkMwSbhoR8XIq/U0tCNyokEi/ueaBMCvbcTHhO7FcwzY92WK4Yt0aGROY5qX2UKSeOvuP4D6TPqKF1onrSzH9bx9XUf2lEdWT/ia1NEKjunUqu1xOB/StKDHMoX4/OKyIzuS0q/T1zOATthvasJFoPrAjkohTyaDUz2LN5JoH839hViyEG82yB+MjcFV5MU3N1l1QL3cVUCh93xSaua1N85qivl+siMkPGbO5xR/En4iEY6K2XPASUEMaieWVNTRCtJ4S8H+9',
    'bitbucket.org ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIIazEu89wgQZ4bqs3d63QSMzYVa0MuJ2e2gKTKqu+UUO',
    'bitbucket.org ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBPIQmuzMBuKdWeF4+a2sjSSpBK0iqitSQ+5BM9KhpexuGt20JpTVM7u5BDZngncgrqDMbWdxMWWOGtZ9UgbqgZE=',
    'bitbucket.org ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABgQDQeJzhupRu0u0cdegZIa8e86EG2qOCsIsD1Xw0xSeiPDlCr7kq97NLmMbpKTX6Esc30NuoqEEHCuc7yWtwp8dI76EEEB1VqY9QJq6vk+aySyboD5QF61I/1WeTwu+deCbgKMGbUijeXhtfbxSxm6JwGrXrhBdofTsbKRUsrN1WoNgUa8uqN1Vx6WAJw1JHPhglEGGHea6QICwJOAr/6mrui/oB7pkaWKHj3z7d1IC4KWLtY47elvjbaTlkN04Kc/5LFEirorGYVbt15kAUlqGM65pk6ZBxtaO3+30LVlORZkxOh+LKL/BvbZ/iRNhItLqNyieoQj/uh/7Iv4uyH/cV/0b4WDSd3DptigWq84lJubb9t/DnZlrJazxyDCulTmKdOR7vs9gMTo+uoIrPSb8ScTtvw65+odKAlBj59dhnVp9zd7QUojOpXlL62Aw56U4oO+FALuevvMjiWeavKhJqlR7i5n9srYcrNV7ttmDw7kf/97P5zauIhxcjX+xHv4M=',
];

function gitSshConfigFile(string $uuid): string
{
    return "/root/.ssh/coolify_git_{$uuid}.config";
}

function gitKnownHostsFile(string $uuid): string
{
    return "/root/.ssh/coolify_git_{$uuid}.known_hosts";
}

/**
 * A known_hosts line without markers, hashed host names, or comments: `host[,host] key-type key`.
 * A host with a custom SSH port is written as `[host]:port`.
 */
function isValidGitKnownHostsLine(string $line): bool
{
    $host = '(?:[A-Za-z0-9.:-]+|\[[A-Za-z0-9.:-]+\]:[0-9]{1,5})';
    $keyType = '(?:ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)|sk-ssh-ed25519@openssh\.com|sk-ecdsa-sha2-nistp256@openssh\.com)';

    return preg_match("/\\A{$host}(?:,{$host})* {$keyType} [A-Za-z0-9+\/]+={0,2}\\z/", $line) === 1;
}

/**
 * The valid known_hosts lines of a text, without blank lines, duplicates, and the pinned keys.
 *
 * @return list<string>
 */
function gitKnownHostsLines(?string $text): array
{
    return collect(preg_split('/\R/', (string) $text))
        ->map(fn (string $line) => preg_replace('/\s+/', ' ', trim($line)))
        ->filter(fn (string $line) => isValidGitKnownHostsLine($line) && ! in_array($line, GIT_PINNED_HOST_KEYS, true))
        ->unique()
        ->values()
        ->all();
}

/**
 * The host, key type, and SHA256 fingerprint of a known_hosts line, in the format that `ssh-keygen -lf` shows.
 *
 * @return array{host: string, type: string, fingerprint: string}
 */
function gitKnownHostFingerprint(string $line): array
{
    [$host, $type, $key] = explode(' ', $line);

    return [
        'host' => $host,
        'type' => $type,
        'fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', (string) base64_decode($key), true)), '='),
    ];
}

/**
 * A shell command that writes the SSH config and known_hosts file that gitSshCommand() uses.
 * Run it in the place where git runs (the helper container or the server) before git uses SSH.
 *
 * The hosts in GIT_PINNED_HOST_KEYS must show one of their published keys. Other hosts are trusted on
 * first use: SSH adds the key of a new host to the known_hosts file and rejects a changed key of a known
 * host. $knownHosts are the keys that earlier deployments saved (Application::$git_ssh_known_hosts).
 * The config of the SSH user is read after these rules, so it cannot turn off the check.
 */
function gitSshSetupCommand(string $uuid, ?string $knownHosts = null): string
{
    $knownHostsFile = gitKnownHostsFile($uuid);
    $pinnedHosts = collect(GIT_PINNED_HOST_KEYS)->map(fn (string $line) => strtok($line, ' '))->unique()->implode(' ');
    $config = implode("\n", [
        "Host {$pinnedHosts}",
        '    StrictHostKeyChecking yes',
        'Host *',
        '    StrictHostKeyChecking accept-new',
        "    UserKnownHostsFile {$knownHostsFile}",
        '    HashKnownHosts no',
        '    Include ~/.ssh/config /etc/ssh/ssh_config',
    ])."\n";
    $knownHostsContent = implode("\n", [...GIT_PINNED_HOST_KEYS, ...gitKnownHostsLines($knownHosts)])."\n";
    $write = fn (string $content, string $file) => "echo '".base64_encode($content)."' | base64 -d | tee {$file} > /dev/null";

    return 'mkdir -p /root/.ssh && '.$write($knownHostsContent, $knownHostsFile).' && '.$write($config, gitSshConfigFile($uuid));
}

/**
 * The SSH command that git uses to reach a repository. Run gitSshSetupCommand() with the same $uuid first.
 * Without a port, SSH uses the port of the repository URL.
 * The keepalive options stop a stalled connection after about one minute; without them git waits until the deployment timeout.
 */
function gitSshCommand(string $uuid, int|string|null $port = null, ?string $identityFile = null, bool $identitiesOnly = true): string
{
    $command = 'ssh -F '.gitSshConfigFile($uuid).' -o ConnectTimeout=30 -o ServerAliveInterval=15 -o ServerAliveCountMax=4';
    if ($port !== null) {
        $command .= " -p {$port} -o Port={$port}";
    }
    $command .= ' -o LogLevel=ERROR';

    if ($identityFile !== null) {
        $command .= " -i {$identityFile}".($identitiesOnly ? ' -o IdentitiesOnly=yes' : '');
    }

    return $command;
}
