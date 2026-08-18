<?php

namespace App\Livewire\Project\Database\Redis;

use App\Models\StandaloneRedis;
use App\Traits\HasDatabaseStatusInfo;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class StatusInfo extends Component
{
    use AuthorizesRequests;
    use HasDatabaseStatusInfo;

    public StandaloneRedis $database;

    protected function databaseLabel(): string
    {
        return 'Redis';
    }
}
