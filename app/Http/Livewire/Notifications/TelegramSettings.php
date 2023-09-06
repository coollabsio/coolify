<?php

namespace App\Http\Livewire\Notifications;

use App\Models\Team;
use App\Notifications\Test;
use Livewire\Component;

class TelegramSettings extends Component
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
        } catch (\Exception $e) {
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
        if (is_a($this->team, Team::class)) {
            refreshSession();
        }
        $this->emit('success', 'Settings saved.');
    }

    public function sendTestNotification()
    {
        $this->team->notify(new Test());
        $this->emit('success', 'Test notification sent.');
    }
}
