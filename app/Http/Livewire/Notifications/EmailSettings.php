<?php

namespace App\Http\Livewire\Notifications;

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Notifications\Notifications\TestNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Component;

class EmailSettings extends Component
{
    public Team $model;

    protected $rules = [
        'model.extra_attributes.smtp_active' => 'nullable|boolean',
        'model.extra_attributes.smtp_from_address' => 'required|email',
        'model.extra_attributes.smtp_from_name' => 'required',
        'model.extra_attributes.smtp_recipients' => 'nullable',
        'model.extra_attributes.smtp_host' => 'required',
        'model.extra_attributes.smtp_port' => 'required',
        'model.extra_attributes.smtp_encryption' => 'nullable',
        'model.extra_attributes.smtp_username' => 'nullable',
        'model.extra_attributes.smtp_password' => 'nullable',
        'model.extra_attributes.smtp_timeout' => 'nullable',
        'model.extra_attributes.smtp_test_recipients' => 'nullable',
        'model.extra_attributes.notifications_email_test' => 'nullable|boolean',
        'model.extra_attributes.notifications_email_deployments' => 'nullable|boolean',
    ];
    protected $validationAttributes = [
        'model.extra_attributes.smtp_from_address' => 'From Address',
        'model.extra_attributes.smtp_from_name' => 'From Name',
        'model.extra_attributes.smtp_recipients' => 'Recipients',
        'model.extra_attributes.smtp_host' => 'Host',
        'model.extra_attributes.smtp_port' => 'Port',
        'model.extra_attributes.smtp_encryption' => 'Encryption',
        'model.extra_attributes.smtp_username' => 'Username',
        'model.extra_attributes.smtp_password' => 'Password',
        'model.extra_attributes.smtp_test_recipients' => 'Test Recipients',
    ];
    public function copySMTP()
    {
        $settings = InstanceSettings::get();
        $this->model->extra_attributes->smtp_active = true;
        $this->model->extra_attributes->smtp_from_address = $settings->extra_attributes->smtp_from_address;
        $this->model->extra_attributes->smtp_from_name = $settings->extra_attributes->smtp_from_name;
        $this->model->extra_attributes->smtp_recipients = $settings->extra_attributes->smtp_recipients;
        $this->model->extra_attributes->smtp_host = $settings->extra_attributes->smtp_host;
        $this->model->extra_attributes->smtp_port = $settings->extra_attributes->smtp_port;
        $this->model->extra_attributes->smtp_encryption = $settings->extra_attributes->smtp_encryption;
        $this->model->extra_attributes->smtp_username = $settings->extra_attributes->smtp_username;
        $this->model->extra_attributes->smtp_password = $settings->extra_attributes->smtp_password;
        $this->model->extra_attributes->smtp_timeout = $settings->extra_attributes->smtp_timeout;
        $this->model->extra_attributes->smtp_test_recipients = $settings->extra_attributes->smtp_test_recipients;
        $this->saveModel();
    }
    public function submit()
    {
        $this->resetErrorBag();
        $this->validate();
        $this->model->extra_attributes->smtp_recipients = str_replace(' ', '', $this->model->extra_attributes->smtp_recipients);
        $this->model->extra_attributes->smtp_test_recipients = str_replace(' ', '', $this->model->extra_attributes->smtp_test_recipients);
        $this->saveModel();
    }
    public function saveModel()
    {
        $this->model->save();
        if (is_a($this->model, Team::class)) {
            session(['currentTeam' => $this->model]);
        }
        $this->emit('success', 'Settings saved.');
    }
    public function sendTestNotification()
    {
        Notification::send($this->model, new TestNotification);
        $this->emit('success', 'Test notification sent.');
    }
    public function instantSave()
    {
        try {
            $this->submit();
        } catch (\Exception $e) {
            $this->model->extra_attributes->smtp_active = false;
            $this->validate();
        }
    }
}
