<?php

namespace App\Livewire\Project\Database\Mariadb;

use App\Models\StandaloneMariadb;
use App\Traits\HasDatabaseStatusInfo;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class StatusInfo extends Component
{
    use AuthorizesRequests;
    use HasDatabaseStatusInfo;

    public StandaloneMariadb $database;

    protected function databaseLabel(): string
    {
        return 'MariaDB';
    }
}
