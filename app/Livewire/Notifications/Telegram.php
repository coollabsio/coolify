<?php

namespace App\Livewire\Notifications;

use App\Models\Team;
use App\Notifications\Test;
use Livewire\Component;

class Telegram extends Component
{
    public Team $team;

    protected $rules = [
        'team.telegram_enabled' => 'nullable|boolean',
        'team.telegram_token' => 'required|string',
        'team.telegram_chat_id' => 'required|string',
        'team.telegram_notifications_test' => 'nullable|boolean',
        'team.telegram_notifications_deployments' => 'nullable|boolean',
        'team.telegram_notifications_status_changes' => 'nullable|boolean',
        'team.telegram_notifications_database_backups' => 'nullable|boolean',
        'team.telegram_notifications_scheduled_tasks' => 'nullable|boolean',
        'team.telegram_notifications_test_message_thread_id' => 'nullable|string',
        'team.telegram_notifications_deployments_message_thread_id' => 'nullable|string',
        'team.telegram_notifications_status_changes_message_thread_id' => 'nullable|string',
        'team.telegram_notifications_database_backups_message_thread_id' => 'nullable|string',
        'team.telegram_notifications_scheduled_tasks_thread_id' => 'nullable|string',
    ];

    protected $validationAttributes = [
        'team.telegram_token' => 'Token',
        'team.telegram_chat_id' => 'Chat ID',
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
            $this->team->telegram_enabled = false;
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
        return view('livewire.notifications.telegram');
    }
}
