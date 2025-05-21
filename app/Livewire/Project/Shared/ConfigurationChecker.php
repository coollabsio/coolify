<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Auth;
use Livewire\Component;

class ConfigurationChecker extends Component
{
    public bool $isConfigurationChanged = false;

    public Application|Service|StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $resource;

    public function getListeners()
    {
        $teamId = Auth::user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ServiceStatusChanged" => 'configurationChanged',
            'configurationChanged',
        ];
    }

    public function mount()
    {
        $this->configurationChanged();
    }

    public function render()
    {
        return view('livewire.project.shared.configuration-checker');
    }

    public function configurationChanged()
    {
        $this->isConfigurationChanged = $this->resource->isConfigurationChanged();
    }
}
