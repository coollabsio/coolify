<?php

namespace App\Http\Livewire\Project\Application;

use App\Enums\ActivityTypes;
use Illuminate\Support\Facades\Redis;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class DeploymentLogs extends Component
{
    public $activity;
    public $isKeepAliveOn = true;
    public $deployment_uuid;

    public function polling()
    {
        if (is_null($this->activity) && isset($this->deployment_uuid)) {
            $this->activity = Activity::query()
                ->where('properties->type', '=', ActivityTypes::DEPLOYMENT->value)
                ->where('properties->type_uuid', '=', $this->deployment_uuid)
                ->first();
        } else {
            $this->activity?->refresh();
        }

        if (data_get($this->activity, 'properties.status') == 'finished' || data_get($this->activity, 'properties.status') == 'failed') {
            $this->isKeepAliveOn = false;
        }
    }
}
