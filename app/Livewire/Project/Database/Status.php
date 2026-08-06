<?php

namespace App\Livewire\Project\Database;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class Status extends Component
{
    public $database;

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
        $this->database->refresh();
    }

    public function render(): View
    {
        return view('livewire.project.database.status');
    }
}
