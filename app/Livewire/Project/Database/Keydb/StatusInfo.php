<?php

namespace App\Livewire\Project\Database\Keydb;

use App\Models\StandaloneKeydb;
use App\Traits\HasDatabaseStatusInfo;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class StatusInfo extends Component
{
    use AuthorizesRequests;
    use HasDatabaseStatusInfo;

    public StandaloneKeydb $database;

    protected function databaseLabel(): string
    {
        return 'KeyDB';
    }

    protected function showPublicUrlPlaceholder(): bool
    {
        return true;
    }
}
