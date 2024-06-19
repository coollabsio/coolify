<?php

namespace App\Livewire;

use App\Models\User;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class NewActivityMonitor extends Component
{
    public ?string $header = null;

    public $activityId;

    public $eventToDispatch = 'activityFinished';

    public $eventData = null;

    public $isPollingActive = false;

    protected $activity;

    protected $listeners = ['newActivityMonitor' => 'newMonitorActivity'];

    public function newMonitorActivity($activityId, $eventToDispatch = 'activityFinished', $eventData = null)
    {
        $this->activityId = $activityId;
        $this->eventToDispatch = $eventToDispatch;
        $this->eventData = $eventData;

        $this->hydrateActivity();

        $this->isPollingActive = true;
    }

    public function hydrateActivity()
    {
        $this->activity = Activity::find($this->activityId);
    }

    public function polling()
    {
        $this->hydrateActivity();
        // $this->setStatus(ProcessStatus::IN_PROGRESS);
        $exit_code = data_get($this->activity, 'properties.exitCode');
        if ($exit_code !== null) {
            // if ($exit_code === 0) {
            //     // $this->setStatus(ProcessStatus::FINISHED);
            // } else {
            //     // $this->setStatus(ProcessStatus::ERROR);
            // }
            $this->isPollingActive = false;
            if ($this->eventToDispatch !== null) {
                if (str($this->eventToDispatch)->startsWith('App\\Events\\')) {
                    $causer_id = data_get($this->activity, 'causer_id');
                    $user = User::find($causer_id);
                    if ($user) {
                        foreach ($user->teams as $team) {
                            $teamId = $team->id;
                            $this->eventToDispatch::dispatch($teamId);
                        }
                    }

                    return;
                }
                if (! is_null($this->eventData)) {
                    $this->dispatch($this->eventToDispatch, $this->eventData);
                } else {
                    $this->dispatch($this->eventToDispatch);
                }
                ray('Dispatched event: '.$this->eventToDispatch.' with data: '.$this->eventData);
            }
        }
    }
}
