<?php

namespace App\Http\Livewire\Settings;

use App\Models\InstanceSettings as ModelsInstanceSettings;
use App\Notifications\TestMessage;
use Illuminate\Support\Facades\Notification;
use Livewire\Component;

class DiscordNotifications extends Component
{
    public ModelsInstanceSettings $settings;

    protected $rules = [
        'settings.extra_attributes.discord_webhook' => 'nullable|url',
    ];
    protected $validationAttributes = [
        'settings.extra_attributes.discord_webhook' => 'Discord Webhook',
    ];
    public function mount($settings)
    {
        //
    }
    public function submit()
    {
        $this->resetErrorBag();
        $this->validate();
        $this->settings->save();
    }
    public function sentTestMessage()
    {
        Notification::send(auth()->user(), new TestMessage);
    }
    public function render()
    {
        return view('livewire.settings.discord-notifications');
    }
}
