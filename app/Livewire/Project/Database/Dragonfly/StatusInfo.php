<?php

namespace App\Livewire\Project\Database\Dragonfly;

use App\Models\StandaloneDragonfly;
use App\Traits\HasDatabaseStatusInfo;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class StatusInfo extends Component
{
    use AuthorizesRequests;
    use HasDatabaseStatusInfo;

    public StandaloneDragonfly $database;

    protected function databaseLabel(): string
    {
        return 'Dragonfly';
    }

    protected function showPublicUrlPlaceholder(): bool
    {
        return true;
    }
}
