<?php

namespace App\Http\Livewire;

use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class PollActivity extends Component
{
    public $activity;
    public $activity_log_id;
    public $isKeepAliveOn = true;
    public function mount() {
        $this->activity = Activity::find($this->activity_log_id);
    }
    public function polling()
    {
        $this->activity?->refresh();
        if (data_get($this->activity, 'properties.exitCode') !== null) {
            $this->isKeepAliveOn = false;
        }
    }
    public function render()
    {
        return view('livewire.poll-activity');
    }
}
