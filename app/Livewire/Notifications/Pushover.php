<?php

namespace App\Livewire\Notifications;

use App\Models\Team;
use App\Notifications\Test;
use Livewire\Component;
use Illuminate\Support\Facades\Log;

class Pushover extends Component
{

    public Team $team;
    protected $rules = [
        'team.pushover_enabled' => 'nullable|boolean',
        'team.pushover_token' => 'required|string',
        'team.pushover_user' => 'required|string',
        'team.pushover_notifications_test' => 'nullable|boolean',
        'team.pushover_notifications_deployments' => 'nullable|boolean',
        'team.pushover_notifications_status_changes' => 'nullable|boolean',
        'team.pushover_notifications_database_backups' => 'nullable|boolean',
    ];
    protected $validationAttributes = [
        'team.pushover_token' => 'Token',
        'team.pushover_user' => 'User Key',
    ];

    public function mount()
    {
        $this->team = auth()->user()->currentTeam();
    }
    public function instantSave()
    {
        try {
            $this->submit();
        } catch (\Throwable $e) {
            ray($e->getMessage());
            $this->team->pushover_enabled = false;
            $this->validate();
        }
    }

    public function submit()
    {
        $this->resetErrorBag();
        $this->validate();
        $this->saveModel();
    }

    public function saveModel()
    {
        $this->team->save();
        refreshSession();
        $this->dispatch('success', 'Settings saved.');
    }

    public function sendTestNotification()
    {
        $this->team?->notify(new Test());
        $this->dispatch('success', 'Test notification sent.');
    }
    public function render()
    {
        return view('livewire.notifications.pushover');
    }
}
