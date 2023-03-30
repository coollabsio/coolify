<?php

namespace App\Actions\RemoteProcess;

use App\Data\RemoteProcessArgs;
use App\Jobs\DeployRemoteProcess;
use App\Jobs\ExecuteRemoteProcess;
use Spatie\Activitylog\Models\Activity;

class DispatchRemoteProcess
{
    protected Activity $activity;

    public function __construct(RemoteProcessArgs $remoteProcessArgs)
    {
        if ($remoteProcessArgs->model) {
            $properties = $remoteProcessArgs->toArray();
            unset($properties['model']);

            $this->activity = activity()
                ->withProperties($properties)
                ->performedOn($remoteProcessArgs->model)
                ->event($remoteProcessArgs->type)
                ->log("");
        } else {
            $this->activity = activity()
                ->withProperties($remoteProcessArgs->toArray())
                ->event($remoteProcessArgs->type)
                ->log("");
        }
    }

    public function __invoke(): Activity
    {
        $job = new ExecuteRemoteProcess($this->activity);
        dispatch($job);
        $this->activity->refresh();
        return $this->activity;
    }
}
