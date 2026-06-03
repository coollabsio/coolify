<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ServerStatusBadge extends Component
{
    public Application $application;

    public function getListeners(): array
    {
        $user = Auth::user();
        if (! $user) {
            return [];
        }

        $team = $user->currentTeam();
        if (! $team) {
            return [];
        }

        return [
            "echo-private:team.{$team->id},ServiceStatusChanged" => 'refreshStatus',
            "echo-private:team.{$team->id},ServiceChecked" => 'refreshStatus',
        ];
    }

    public function refreshStatus(): void
    {
        $this->application->refresh();
    }

    public function render(): View
    {
        return view('livewire.project.application.server-status-badge');
    }
}
