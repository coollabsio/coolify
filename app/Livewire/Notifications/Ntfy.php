<?php

namespace App\Livewire\Notifications;

use App\Models\Team;
use App\Notifications\Test;
use Livewire\Component;

class Ntfy extends Component
{
    public Team $team;

    protected $rules = [
        'team.ntfy_enabled' => 'nullable|boolean',
        'team.ntfy_url' => 'required|url',
        'team.ntfy_topic' => 'required|string',
        'team.ntfy_username' => 'nullable|string',
        'team.ntfy_password' => 'nullable|string',
        'team.ntfy_notifications_test' => 'nullable|boolean',
        'team.ntfy_notifications_deployments' => 'nullable|boolean',
        'team.ntfy_notifications_status_changes' => 'nullable|boolean',
        'team.ntfy_notifications_database_backups' => 'nullable|boolean',
        'team.ntfy_notifications_scheduled_tasks' => 'nullable|boolean',
    ];

    /* shelll */
    protected $validationAttributes = [
        'team.ntfy_url' => 'Host',
        'team.ntfy_topic' => 'Topic',
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
            $this->team->ntfy_enabled = false;
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
        return view('livewire.notifications.ntfy');
    }
}
