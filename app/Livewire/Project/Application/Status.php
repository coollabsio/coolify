<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Status extends Component
{
    public Application $application;

    public function getListeners(): array
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ServiceStatusChanged" => 'refreshStatus',
            "echo-private:team.{$teamId},ServiceChecked" => 'refreshStatus',
        ];
    }

    public function refreshStatus(): void
    {
        $this->application->refresh();
    }

    public function render(): View
    {
        return view('livewire.project.application.status');
    }
}
