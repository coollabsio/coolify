<?php

namespace App\Http\Livewire\Notifications;

use App\Models\Team;
use App\Notifications\Notifications\TestNotification;
use Livewire\Component;

class DiscordSettings extends Component
{
    public Team $model;
    protected $rules = [
        'model.discord.enabled' => 'nullable|boolean',
        'model.discord.webhook_url' => 'required|url',
        'model.discord_notifications.test' => 'nullable|boolean',
        'model.discord_notifications.deployments' => 'nullable|boolean',
        'model.discord_notifications.stopped' => 'nullable|boolean',
    ];
    protected $validationAttributes = [
        'model.discord.webhook_url' => 'Discord Webhook',
    ];
    public function instantSave()
    {
        try {
            $this->submit();
        } catch (\Exception $e) {
            $this->model->discord->enabled = false;
            $this->validate();
        }
    }
    public function saveModel()
    {
        $this->model->save();
        if (is_a($this->model, Team::class)) {
            session(['currentTeam' => $this->model]);
        }
        $this->emit('success', 'Settings saved.');
    }
    public function submit()
    {
        $this->resetErrorBag();
        $this->validate();
        $this->saveModel();
    }
    public function sendTestNotification()
    {
        $this->model->notify(new TestNotification('discord'));
        $this->emit('success', 'Test notification sent.');
    }
}
