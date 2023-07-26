<?php

namespace App\Http\Livewire\Notifications;

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Notifications\Notifications\TestNotification;
use Livewire\Component;

class EmailSettings extends Component
{
    public Team $model;

    protected $rules = [
        'model.smtp.enabled' => 'nullable|boolean',
        'model.smtp.from_address' => 'required|email',
        'model.smtp.from_name' => 'required',
        'model.smtp.recipients' => 'nullable',
        'model.smtp.host' => 'required',
        'model.smtp.port' => 'required',
        'model.smtp.encryption' => 'nullable',
        'model.smtp.username' => 'nullable',
        'model.smtp.password' => 'nullable',
        'model.smtp.timeout' => 'nullable',
        'model.smtp.test_recipients' => 'nullable',
        'model.smtp_notifications.test' => 'nullable|boolean',
        'model.smtp_notifications.deployments' => 'nullable|boolean',
        'model.smtp_notifications.stopped' => 'nullable|boolean',
    ];
    protected $validationAttributes = [
        'model.smtp.from_address' => 'From Address',
        'model.smtp.from_name' => 'From Name',
        'model.smtp.recipients' => 'Recipients',
        'model.smtp.host' => 'Host',
        'model.smtp.port' => 'Port',
        'model.smtp.encryption' => 'Encryption',
        'model.smtp.username' => 'Username',
        'model.smtp.password' => 'Password',
        'model.smtp.test_recipients' => 'Test Recipients',
    ];
    private function decrypt()
    {
        if (data_get($this->model, 'smtp.password')) {
            try {
                $this->model->smtp->password = decrypt($this->model->smtp->password);
            } catch (\Exception $e) {
            }
        }
    }
    public function mount()
    {
        $this->decrypt();
    }
    public function copyFromInstanceSettings()
    {
        $settings = InstanceSettings::get();
        if ($settings->smtp->enabled) {
            $this->model->smtp->enabled = true;
            $this->model->smtp->from_address = $settings->smtp->from_address;
            $this->model->smtp->from_name = $settings->smtp->from_name;
            $this->model->smtp->recipients = $settings->smtp->recipients;
            $this->model->smtp->host = $settings->smtp->host;
            $this->model->smtp->port = $settings->smtp->port;
            $this->model->smtp->encryption = $settings->smtp->encryption;
            $this->model->smtp->username = $settings->smtp->username;
            $this->model->smtp->password = $settings->smtp->password;
            $this->model->smtp->timeout = $settings->smtp->timeout;
            $this->model->smtp->test_recipients = $settings->smtp->test_recipients;
            $this->saveModel();
        } else {
            $this->emit('error', 'Instance SMTP settings are not enabled.');
        }
    }
    public function submit()
    {
        $this->resetErrorBag();
        $this->validate();

        if ($this->model->smtp->password) {
            $this->model->smtp->password = encrypt($this->model->smtp->password);
        } else {
            $this->model->smtp->password = null;
        }

        $this->model->smtp->recipients = str_replace(' ', '', $this->model->smtp->recipients);
        $this->model->smtp->test_recipients = str_replace(' ', '', $this->model->smtp->test_recipients);
        $this->saveModel();
    }
    public function saveModel()
    {
        $this->model->save();
        $this->decrypt();
        if (is_a($this->model, Team::class)) {
            session(['currentTeam' => $this->model]);
        }
        $this->emit('success', 'Settings saved.');
    }
    public function sendTestNotification()
    {
        $this->model->notify(new TestNotification('smtp'));
        $this->emit('success', 'Test notification sent.');
    }
    public function instantSave()
    {
        try {
            $this->submit();
        } catch (\Exception $e) {
            $this->model->smtp->enabled = false;
            $this->validate();
        }
    }
}
