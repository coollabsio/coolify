<?php

namespace App\Livewire\Project\Database\Influxdb;

use App\Models\StandaloneInfluxdb;
use App\Traits\HasDatabaseStatusInfo;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class StatusInfo extends Component
{
    use AuthorizesRequests;
    use HasDatabaseStatusInfo;

    public StandaloneInfluxdb $database;

    protected function databaseLabel(): string
    {
        return 'InfluxDB';
    }

    protected function supportsSsl(): bool
    {
        return false;
    }

    protected function showPublicUrlPlaceholder(): bool
    {
        return true;
    }
}
