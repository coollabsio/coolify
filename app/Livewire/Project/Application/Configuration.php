<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\Server;
use Livewire\Component;

class Configuration extends Component
{
    public Application $application;

    public $servers;

    protected $listeners = ['buildPackUpdated' => '$refresh'];

    public function mount()
    {
        $this->application = Application::query()
            ->whereHas('environment.project', function ($query) {
                $query->where('team_id', currentTeam()->id)
                    ->where('uuid', request()->route('project_uuid'));
            })
            ->whereHas('environment', function ($query) {
                $query->where('name', request()->route('environment_name'));
            })
            ->where('uuid', request()->route('application_uuid'))
            ->with(['destination' => function ($query) {
                $query->select('id', 'server_id');
            }])
            ->firstOrFail();

        if ($this->application->destination && $this->application->destination->server_id) {
            $this->servers = Server::ownedByCurrentTeam()
                ->select('id', 'name')
                ->where('id', '!=', $this->application->destination->server_id)
                ->get();
        } else {
            $this->servers = collect();
        }
    }

    public function render()
    {
        return view('livewire.project.application.configuration');
    }
}
