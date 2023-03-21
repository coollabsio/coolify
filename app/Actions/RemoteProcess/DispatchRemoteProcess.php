<?php

namespace App\Actions\RemoteProcess;

use App\Data\RemoteProcessArgs;
use App\Jobs\ExecuteCoolifyProcess;
use Spatie\Activitylog\Models\Activity;

class DispatchRemoteProcess
{
    protected Activity $activity;

    public function __construct(RemoteProcessArgs $remoteProcessArgs){
        $this->activity = activity()
            ->withProperties($remoteProcessArgs->toArray())
            ->log("Awaiting command to start...\n\n");
    }

    public function __invoke(): Activity
    {
        $job = new ExecuteCoolifyProcess($this->activity);

        dispatch($job);

        $this->activity->refresh();

        return $this->activity;
    }
}
