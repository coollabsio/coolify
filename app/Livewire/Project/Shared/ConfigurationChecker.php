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
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ConfigurationChecker extends Component
{
    public bool $isConfigurationChanged = false;

    public array $configurationDiff = [];

    public array $groupedConfigurationChanges = [];

    public Application|Service|StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $resource;

    public function getListeners(): array
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ApplicationConfigurationChanged" => 'configurationChanged',
            'configurationChanged' => 'configurationChanged',
        ];
    }

    public function mount(): void
    {
        $this->configurationChanged();
    }

    public function render(): View
    {
        return view('livewire.project.shared.configuration-checker');
    }

    public function refreshConfigurationChanges(): void
    {
        $this->configurationChanged();
    }

    public function configurationChanged(): void
    {
        $this->resource->refresh();

        if ($this->resource instanceof Application) {
            $diff = $this->resource->pendingDeploymentConfigurationDiff();
            $this->isConfigurationChanged = $diff->isChanged();
            $this->configurationDiff = $diff->toArray();
            $this->groupedConfigurationChanges = $diff->groupedChanges();

            return;
        }

        $this->isConfigurationChanged = $this->resource->isConfigurationChanged();
        $this->configurationDiff = [];
        $this->groupedConfigurationChanges = [];
    }
}
