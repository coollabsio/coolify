<?php

namespace App\Livewire\Project\Database\Cassandra;

use App\Models\StandaloneCassandra;
use App\Traits\HasDatabaseStatusInfo;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class StatusInfo extends Component
{
    use AuthorizesRequests;
    use HasDatabaseStatusInfo;

    public StandaloneCassandra $database;

    protected function databaseLabel(): string
    {
        return 'Cassandra';
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
