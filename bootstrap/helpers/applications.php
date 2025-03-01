<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;
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
