<?php

namespace App\Http\Livewire;

use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class ActivityMonitor extends Component
{
    public $activityId;
    public $isPollingActive = false;

    protected $activity;
    protected $listeners = ['newMonitorActivity'];

    public function hydrateActivity()
    {
        $this->activity = Activity::query()
            ->find($this->activityId);
    }

    public function newMonitorActivity($activityId)
    {
        $this->activityId = $activityId;

        $this->hydrateActivity();

        $this->isPollingActive = true;
    }

    public function polling()
    {
        $this->hydrateActivity();

        if (data_get($this->activity, 'properties.exitCode') !== null) {
            $this->isPollingActive = false;
        }
    }

    public function render()
    {
        return view('livewire.activity-monitor');
    }
}
