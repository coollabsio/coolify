<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Support\Facades\DB;
use Spatie\Url\Url;

function queue_application_deployment(Application $application, string $deployment_uuid, ?int $pull_request_id = 0, string $commit = 'HEAD', bool $force_rebuild = false, bool $is_webhook = false, bool $is_api = false, bool $restart_only = false, ?string $git_type = null, bool $no_questions_asked = false, ?Server $server = null, ?StandaloneDocker $destination = null, bool $only_this_server = false, bool $rollback = false)
{
    return DB::transaction(function () use ($application, $deployment_uuid, $pull_request_id, $commit, $force_rebuild, $is_webhook, $is_api, $restart_only, $git_type, $no_questions_asked, $server, $destination, $only_this_server, $rollback) {
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

        // Check if there's already a deployment in progress for this application
        $existing_deployment = ApplicationDeploymentQueue::where('application_id', $application_id)
            ->whereIn('status', [ApplicationDeploymentStatus::IN_PROGRESS, ApplicationDeploymentStatus::QUEUED])
            ->lockForUpdate()
            ->first();

        if ($existing_deployment && ! $force_rebuild) {
            throw new \RuntimeException('A deployment is already in progress or queued for this application.');
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
            'status' => ApplicationDeploymentStatus::QUEUED,
        ]);

        if ($no_questions_asked || next_queuable($server_id, $application_id)) {
            $deployment->update([
                'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
            ]);

            ApplicationDeploymentJob::dispatch(
                application_deployment_queue_id: $deployment->id
            )->onQueue('high');
        }

        return $deployment;
    });
}
function force_start_deployment(ApplicationDeploymentQueue $deployment)
{
    DB::transaction(function () use ($deployment) {
        $deployment = ApplicationDeploymentQueue::lockForUpdate()->find($deployment->id);

        if (! $deployment) {
            throw new \RuntimeException('Deployment not found.');
        }

        $deployment->update([
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);

        ApplicationDeploymentJob::dispatch(
            application_deployment_queue_id: $deployment->id
        )->onQueue('high');
    });
}
function queue_next_deployment(Application $application)
{
    $server_id = $application->destination->server_id;

    // Use transaction to prevent race conditions
    DB::transaction(function () use ($server_id) {
        // Lock the queued deployments for update to prevent race conditions
        $next_found = ApplicationDeploymentQueue::where('server_id', $server_id)
            ->where('status', ApplicationDeploymentStatus::QUEUED)
            ->orderBy('created_at')
            ->lockForUpdate()
            ->first();

        if ($next_found) {
            // Check if we can start this deployment
            $server = Server::find($server_id);
            $concurrent_builds = $server->settings->concurrent_builds;

            $in_progress_count = ApplicationDeploymentQueue::where('server_id', $server_id)
                ->where('status', 'in_progress')
                ->count();

            if ($in_progress_count < $concurrent_builds) {
                $next_found->update([
                    'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
                ]);

                ApplicationDeploymentJob::dispatch(
                    application_deployment_queue_id: $next_found->id
                )->onQueue('high');
            }
        }
    });
}

function next_queuable(string $server_id, string $application_id): bool
{
    $deployments = ApplicationDeploymentQueue::where('server_id', $server_id)
        ->whereIn('status', ['in_progress', ApplicationDeploymentStatus::QUEUED])
        ->get()
        ->sortByDesc('created_at');

    // Check if there are any deployments in progress for this application
    $same_application_deployments = $deployments->where('application_id', $application_id);
    $in_progress = $same_application_deployments->filter(function ($value) {
        return $value->status === 'in_progress';
    });

    if ($in_progress->count() > 0) {
        return false;
    }

    $server = Server::find($server_id);
    $concurrent_builds = $server->settings->concurrent_builds;

    // Count only in_progress deployments for concurrent limit
    $in_progress_count = $deployments->filter(function ($value) {
        return $value->status === 'in_progress';
    })->count();

    return $in_progress_count < $concurrent_builds;
}
function next_after_cancel(?Server $server = null)
{
    if ($server) {
        DB::transaction(function () use ($server) {
            // Lock the queued deployments for update to prevent race conditions
            $next_found = ApplicationDeploymentQueue::where('server_id', $server->id)
                ->where('status', ApplicationDeploymentStatus::QUEUED)
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();

            if ($next_found) {
                $server = Server::find($next_found->server_id);
                $concurrent_builds = $server->settings->concurrent_builds;

                $in_progress_count = ApplicationDeploymentQueue::where('server_id', $next_found->server_id)
                    ->where('status', ApplicationDeploymentStatus::IN_PROGRESS)
                    ->count();

                if ($in_progress_count < $concurrent_builds) {
                    $next_found->update([
                        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
                    ]);

                    ApplicationDeploymentJob::dispatch(
                        application_deployment_queue_id: $next_found->id
                    )->onQueue('high');
                }
            }
        });
    }
}
