<?php

namespace App\Http\Livewire\Notifications;

use App\Models\Server;
use App\Models\Team;
use App\Notifications\DemoNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Component;

class DiscordSettings extends Component
{
    public Team|Server $model;

    protected $rules = [
        'model.extra_attributes.discord_webhook' => 'nullable|url',
        'model.extra_attributes.discord_active' => 'nullable|boolean',
    ];
    protected $validationAttributes = [
        'model.extra_attributes.discord_webhook' => 'Discord Webhook',
    ];
    public function mount($model)
    {
        //
    }
    public function submit()
    {
        $this->resetErrorBag();
        $this->validate();
        $this->model->save();
        if ( is_a($this->model, Team::class)) {
            session(['currentTeam' => $this->model]);
        }
    }
    public function sendTestNotification()
    {
        Notification::send($this->model, new DemoNotification);
    }
    public function render()
    {
        return view('livewire.notifications.discord-settings');
    }
}
