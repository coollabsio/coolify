<?php

namespace App\Livewire\Notifications;

use App\Models\Team;
use App\Notifications\Test;
use Livewire\Component;

class Discord extends Component
{
    public Team $team;

    protected $rules = [
        'team.discord_enabled' => 'nullable|boolean',
        'team.discord_webhook_url' => 'required|url',
        'team.discord_notifications_test' => 'nullable|boolean',
        'team.discord_notifications_deployments' => 'nullable|boolean',
        'team.discord_notifications_status_changes' => 'nullable|boolean',
        'team.discord_notifications_database_backups' => 'nullable|boolean',
        'team.discord_notifications_scheduled_tasks' => 'nullable|boolean',
    ];

    protected $validationAttributes = [
        'team.discord_webhook_url' => 'Discord Webhook',
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
            $this->team->discord_enabled = false;
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
        $this->team?->notify(new Test);
        $this->dispatch('success', 'Test notification sent.');
    }

    public function render()
    {
        return view('livewire.notifications.discord');
    }
}
