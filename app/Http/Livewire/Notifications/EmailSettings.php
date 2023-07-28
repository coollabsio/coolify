<?php

namespace App\Http\Livewire\Notifications;

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Notifications\Test;
use Livewire\Component;

class EmailSettings extends Component
{
    public Team $model;
    public string $emails;

    protected $rules = [
        'model.smtp_enabled' => 'nullable|boolean',
        'model.smtp_from_address' => 'required|email',
        'model.smtp_from_name' => 'required',
        'model.smtp_recipients' => 'nullable',
        'model.smtp_host' => 'required',
        'model.smtp_port' => 'required',
        'model.smtp_encryption' => 'nullable',
        'model.smtp_username' => 'nullable',
        'model.smtp_password' => 'nullable',
        'model.smtp_timeout' => 'nullable',
        'model.smtp_notifications_test' => 'nullable|boolean',
        'model.smtp_notifications_deployments' => 'nullable|boolean',
        'model.smtp_notifications_status_changes' => 'nullable|boolean',
    ];
    protected $validationAttributes = [
        'model.smtp_from_address' => 'From Address',
        'model.smtp_from_name' => 'From Name',
        'model.smtp_recipients' => 'Recipients',
        'model.smtp_host' => 'Host',
        'model.smtp_port' => 'Port',
        'model.smtp_encryption' => 'Encryption',
        'model.smtp_username' => 'Username',
        'model.smtp_password' => 'Password',
    ];
    private function decrypt()
    {
        if (data_get($this->model, 'smtp_password')) {
            try {
                $this->model->smtp_password = decrypt($this->model->smtp_password);
            } catch (\Exception $e) {
            }
        }
    }
    public function mount()
    {
        $this->decrypt();
        $this->emails = auth()->user()->email;
    }
    public function copyFromInstanceSettings()
    {
        $settings = InstanceSettings::get();
        if ($settings->smtp_enabled) {
            $team = auth()->user()->currentTeam();
            $team->update([
                'smtp_enabled' => $settings->smtp_enabled,
                'smtp_from_address' => $settings->smtp_from_address,
                'smtp_from_name' => $settings->smtp_from_name,
                'smtp_recipients' => $settings->smtp_recipients,
                'smtp_host' => $settings->smtp_host,
                'smtp_port' => $settings->smtp_port,
                'smtp_encryption' => $settings->smtp_encryption,
                'smtp_username' => $settings->smtp_username,
                'smtp_password' => $settings->smtp_password,
                'smtp_timeout' => $settings->smtp_timeout,
            ]);
            $this->decrypt();
            if (is_a($team, Team::class)) {
                session(['currentTeam' => $team]);
            }
            $this->model = $team;
            $this->emit('success', 'Settings saved.');
        } else {
            $this->emit('error', 'Instance SMTP settings are not enabled.');
        }
    }
    public function submit()
    {
        $this->resetErrorBag();
        $this->validate();

        if ($this->model->smtp_password) {
            $this->model->smtp_password = encrypt($this->model->smtp_password);
        } else {
            $this->model->smtp_password = null;
        }

        $this->model->smtp_recipients = str_replace(' ', '', $this->model->smtp_recipients);
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
        $this->model->notify(new Test($this->emails));
        $this->emit('success', 'Test Email sent successfully.');
    }
    public function instantSave()
    {
        try {
            $this->submit();
        } catch (\Exception $e) {
            $this->model->smtp_enabled = false;
            $this->validate();
        }
    }
}