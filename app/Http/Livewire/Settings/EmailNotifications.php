<?php

namespace App\Http\Livewire\Settings;

use App\Models\InstanceSettings as ModelsInstanceSettings;
use App\Notifications\TestMessage;
use Illuminate\Support\Facades\Notification;
use Livewire\Component;

class EmailNotifications extends Component
{
    public ModelsInstanceSettings $settings;

    protected $rules = [
        'settings.extra_attributes.smtp_host' => 'nullable',
        'settings.extra_attributes.smtp_port' => 'nullable',
        'settings.extra_attributes.smtp_encryption' => 'nullable',
        'settings.extra_attributes.smtp_username' => 'nullable',
        'settings.extra_attributes.smtp_password' => 'nullable',
        'settings.extra_attributes.smtp_timeout' => 'nullable',
    ];
    protected $validationAttributes = [
        'settings.extra_attributes.smtp_host' => 'Host',
        'settings.extra_attributes.smtp_port' => 'Port',
        'settings.extra_attributes.smtp_encryption' => 'Encryption',
        'settings.extra_attributes.smtp_username' => 'Username',
        'settings.extra_attributes.smtp_password' => 'Password',
        'settings.extra_attributes.smtp_timeout' => 'Timeout',
    ];
    public function mount($settings)
    {
        ray($settings);
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
        return view('livewire.settings.email-notifications');
    }
}
