<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;

function queue_application_deployment(int $application_id, string $deployment_uuid, int|null $pull_request_id = 0, string $commit = 'HEAD', bool $force_rebuild = false, bool $is_webhook = false)
{
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $application_id,
        'deployment_uuid' => $deployment_uuid,
        'pull_request_id' => $pull_request_id,
        'force_rebuild' => $force_rebuild,
        'is_webhook' => $is_webhook,
        'commit' => $commit,
    ]);
    $queued_deployments = ApplicationDeploymentQueue::where('application_id', $application_id)->where('status', 'queued')->get()->sortByDesc('created_at');
    $running_deployments = ApplicationDeploymentQueue::where('application_id', $application_id)->where('status', 'in_progress')->get()->sortByDesc('created_at');
    ray('Q:' . $queued_deployments->count() . 'R:' . $running_deployments->count() .  '| Queuing deployment: ' . $deployment_uuid . ' of applicationID: ' . $application_id . ' pull request: ' . $pull_request_id . ' with commit: ' . $commit . ' and is it forced: ' . $force_rebuild);
    if ($queued_deployments->count() > 1) {
        $queued_deployments = $queued_deployments->skip(1);
        $queued_deployments->each(function ($queued_deployment, $key) {
            $queued_deployment->status = 'cancelled by system';
            $queued_deployment->save();
        });
    }
    if ($running_deployments->count() > 0) {
        return;
    }
    dispatch(new ApplicationDeploymentJob(
        application_deployment_queue_id: $deployment->id,
        application_id: $application_id,
        deployment_uuid: $deployment_uuid,
        force_rebuild: $force_rebuild,
        rollback_commit: $commit,
        pull_request_id: $pull_request_id,
    ))->onConnection('long-running')->onQueue('long-running');
}

function queue_next_deployment(Application $application)
{
    $next_found = ApplicationDeploymentQueue::where('application_id', $application->id)->where('status', 'queued')->first();
    if ($next_found) {
        dispatch(new ApplicationDeploymentJob(
            application_deployment_queue_id: $next_found->id,
            application_id: $next_found->application_id,
            deployment_uuid: $next_found->deployment_uuid,
            force_rebuild: $next_found->force_rebuild,
            pull_request_id: $next_found->pull_request_id
        ))->onConnection('long-running')->onQueue('long-running');
    }
}
