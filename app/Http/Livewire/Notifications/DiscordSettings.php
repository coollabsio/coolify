<?php

namespace App\Http\Livewire\Notifications;

use App\Models\Team;
use App\Notifications\Test;
use Livewire\Component;

class DiscordSettings extends Component
{
    public Team $model;
    protected $rules = [
        'model.discord_enabled' => 'nullable|boolean',
        'model.discord_webhook_url' => 'required|url',
        'model.discord_notifications_test' => 'nullable|boolean',
        'model.discord_notifications_deployments' => 'nullable|boolean',
        'model.discord_notifications_status_changes' => 'nullable|boolean',
    ];
    protected $validationAttributes = [
        'model.discord_webhook_url' => 'Discord Webhook',
    ];

    public function instantSave()
    {
        try {
            $this->submit();
        } catch (\Exception $e) {
            ray($e->getMessage());
            $this->model->discord_enabled = false;
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
        ray($this->model);
        $this->model->save();
        if (is_a($this->model, Team::class)) {
            session(['currentTeam' => $this->model]);
        }
        $this->emit('success', 'Settings saved.');
    }

    public function sendTestNotification()
    {
        $this->model->notify(new Test);
        $this->emit('success', 'Test notification sent.');
    }
}
