<?php

namespace App\Http\Livewire\Project\Application;

use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class GetDeployments extends Component
{
    public string $deployment_uuid;
    public string $created_at;
    public string $status;
    public function polling()
    {
        $activity = Activity::where('properties->deployment_uuid', '=', $this->deployment_uuid)->first();
        $this->created_at = $activity->created_at;
        $this->status = data_get($activity, 'properties.status');
    }
}
