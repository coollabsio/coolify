<?php

namespace App\Livewire\Notifications;

use App\Models\Team;
use App\Notifications\Test;
use Livewire\Component;

class Slack extends Component
{
    public Team $team;

    protected $rules = [
        'team.slack_enabled' => 'nullable|boolean',
        'team.slack_webhook_url' => 'required|url',
        'team.slack_notifications_test' => 'nullable|boolean',
        'team.slack_notifications_deployments' => 'nullable|boolean',
        'team.slack_notifications_status_changes' => 'nullable|boolean',
        'team.slack_notifications_database_backups' => 'nullable|boolean',
        'team.slack_notifications_scheduled_tasks' => 'nullable|boolean',
    ];

    protected $validationAttributes = [
        'team.slack_webhook_url' => 'Slack Web API',
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
            $this->team->slack_enabled = false;
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
        return view('livewire.notifications.slack');
    }
}
