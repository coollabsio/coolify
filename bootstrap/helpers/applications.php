<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;

function queue_application_deployment(Application $application, $metadata)
{
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'metadata' => $metadata,
    ]);
    $queued_deployments = ApplicationDeploymentQueue::where('application_id', $application->id)->where('status', 'queued')->get()->sortByDesc('created_at');
    $running_deployments = ApplicationDeploymentQueue::where('application_id', $application->id)->where('status', 'in_progress')->get()->sortByDesc('created_at');
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
        deployment_uuid: $metadata['deployment_uuid'],
        application_uuid: $metadata['application_uuid'],
        force_rebuild: $metadata['force_rebuild'],
        commit: $metadata['commit'] ?? null,
    ));
}
