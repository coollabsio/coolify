<?php

namespace App\Http\Livewire\Notifications;

use App\Models\Server;
use App\Models\Team;
use App\Notifications\Notifications\TestNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Component;

class DiscordSettings extends Component
{
    public Team $model;

    protected $rules = [
        'model.extra_attributes.discord_active' => 'nullable|boolean',
        'model.extra_attributes.discord_webhook' => 'required|url',
        'model.extra_attributes.notifications_discord_test' => 'nullable|boolean',
        'model.extra_attributes.notifications_discord_deployments' => 'nullable|boolean',
    ];
    protected $validationAttributes = [
        'model.extra_attributes.discord_webhook' => 'Discord Webhook',
    ];
    public function instantSave()
    {
        try {
            $this->submit();
        } catch (\Exception $e) {
            $this->model->extra_attributes->discord_active = false;
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
        Notification::send($this->model, new TestNotification);
    }
}
