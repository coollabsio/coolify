<?php

namespace App\Livewire\Project\Database\Clickhouse;

use App\Models\StandaloneClickhouse;
use App\Traits\HasDatabaseStatusInfo;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class StatusInfo extends Component
{
    use AuthorizesRequests;
    use HasDatabaseStatusInfo;

    public StandaloneClickhouse $database;

    protected function databaseLabel(): string
    {
        return 'Clickhouse';
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
