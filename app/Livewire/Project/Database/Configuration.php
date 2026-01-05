<?php

namespace App\Livewire\Project\Database;

use Auth;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Configuration extends Component
{
    use AuthorizesRequests;

    public $currentRoute;

    public $database;

    public $project;

    public $environment;

    public function getListeners()
    {
        $teamId = Auth::user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ServiceChecked" => '$refresh',
        ];
    }

    public function mount()
    {
        try {
            $this->currentRoute = request()->route()->getName();

            $project = currentTeam()
                ->projects()
                ->select('id', 'uuid', 'team_id')
                ->where('uuid', request()->route('project_uuid'))
                ->firstOrFail();
            $environment = $project->environments()
                ->select('id', 'name', 'project_id', 'uuid')
                ->where('uuid', request()->route('environment_uuid'))
                ->firstOrFail();
            $database = $environment->databases()
                ->where('uuid', request()->route('database_uuid'))
                ->firstOrFail();

            $this->authorize('view', $database);

            $this->database = $database;
            $this->project = $project;
            $this->environment = $environment;
            if (str($this->database->status)->startsWith('running') && is_null($this->database->config_hash)) {
                $this->database->isConfigurationChanged(true);
                $this->dispatch('configurationChanged');
            }
        } catch (\Throwable $e) {
            if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                return redirect()->route('dashboard');
            }
            if ($e instanceof \Illuminate\Support\ItemNotFoundException) {
                return redirect()->route('dashboard');
            }

            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.database.configuration');
    }
}
