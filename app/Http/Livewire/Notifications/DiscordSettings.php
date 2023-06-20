<?php

namespace App\Http\Livewire\Notifications;

use App\Models\Team;
use App\Notifications\Notifications\TestNotification;
use Livewire\Component;

class DiscordSettings extends Component
{
    public Team $model;
    protected $rules = [
        'model.extra_attributes.discord_enabled' => 'nullable|boolean',
        'model.extra_attributes.discord_webhook_url' => 'required|url',
        'model.extra_attributes.notifications_discord_test' => 'nullable|boolean',
        'model.extra_attributes.notifications_discord_deployments' => 'nullable|boolean',
    ];
    protected $validationAttributes = [
        'model.extra_attributes.discord_webhook_url' => 'Discord Webhook',
    ];
    public function instantSave()
    {
        try {
            $this->submit();
        } catch (\Exception $e) {
            $this->model->extra_attributes->discord_enabled = false;
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
