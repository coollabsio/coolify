<?php

namespace App\Http\Livewire;

use App\Enums\ProcessStatus;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class ActivityMonitor extends Component
{
    public bool $header = false;
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
        $this->setStatus(ProcessStatus::IN_PROGRESS);
        $exit_code = data_get($this->activity, 'properties.exitCode');
        if ($exit_code !== null) {
            if ($exit_code === 0) {
                $this->setStatus(ProcessStatus::FINISHED);
            } else {
                $this->setStatus(ProcessStatus::ERROR);
            }
            $this->isPollingActive = false;
            $this->emit('activityFinished');
        }
    }
    protected function setStatus($status)
    {
        $this->activity->properties = $this->activity->properties->merge([
            'status' => $status,
        ]);
        $this->activity->save();
    }
}