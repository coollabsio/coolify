<?php

namespace App\Http\Livewire;

use App\Enums\ActivityTypes;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class PollActivity extends Component
{
    public $activity;
    public $isKeepAliveOn = true;
    public $deployment_uuid;

    public function polling()
    {
        if ( is_null($this->activity) && isset($this->deployment_uuid)) {
            $this->activity = Activity::query()
                ->where('properties->type', '=', ActivityTypes::DEPLOYMENT->value)
                ->where('properties->type_uuid', '=', $this->deployment_uuid)
                ->first();
        } else {
            $this->activity?->refresh();
        }

        if (data_get($this->activity, 'properties.status') == 'finished' || data_get($this->activity, 'properties.status') == 'failed' ) {
            $this->isKeepAliveOn = false;
        }
    }

    public function render()
    {
        return view('livewire.poll-activity');
    }
}
