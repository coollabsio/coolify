<?php

namespace App\Actions\CoolifyTask;

use App\Data\CoolifyTaskArgs;
use App\Jobs\CoolifyTask;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * The initial step to run a `CoolifyTask`: a remote SSH process
 * with monitoring/tracking/trace feature. Such thing is made
 * possible using an Activity model and some attributes.
 */
class PrepareCoolifyTask
{
    protected Activity $activity;

    protected CoolifyTaskArgs $remoteProcessArgs;

    public function __construct(CoolifyTaskArgs $coolifyTaskArgs)
    {
        $this->remoteProcessArgs = $coolifyTaskArgs;

        if ($coolifyTaskArgs->model instanceof Model) {
            $properties = $coolifyTaskArgs->toArray();
            unset($properties['model']);

            $this->activity = activity()
                ->withProperties($properties)
                ->performedOn($coolifyTaskArgs->model)
                ->event($coolifyTaskArgs->type)
                ->log('[]');
        } else {
            $this->activity = activity()
                ->withProperties($coolifyTaskArgs->toArray())
                ->event($coolifyTaskArgs->type)
                ->log('[]');
        }
    }

    public function __invoke(): Activity
    {
        $coolifyTask = new CoolifyTask(
            activity: $this->activity,
            ignore_errors: $this->remoteProcessArgs->ignore_errors,
            call_event_on_finish: $this->remoteProcessArgs->call_event_on_finish,
            call_event_data: $this->remoteProcessArgs->call_event_data,
        );
        dispatch($coolifyTask);
        $this->activity->refresh();

        return $this->activity;
    }
}
