<?php

namespace App\Livewire\Notifications;

use App\Models\Team;
use App\Notifications\Test;
use Livewire\Component;

class Email extends Component
{
    public Team $team;

    public string $emails;

    public bool $sharedEmailEnabled = false;

    protected $rules = [
        'team.smtp_enabled' => 'nullable|boolean',
        'team.smtp_from_address' => 'required|email',
        'team.smtp_from_name' => 'required',
        'team.smtp_recipients' => 'nullable',
        'team.smtp_host' => 'required',
        'team.smtp_port' => 'required',
        'team.smtp_encryption' => 'nullable',
        'team.smtp_username' => 'nullable',
        'team.smtp_password' => 'nullable',
        'team.smtp_timeout' => 'nullable',
        'team.smtp_notifications_test' => 'nullable|boolean',
        'team.smtp_notifications_deployments' => 'nullable|boolean',
        'team.smtp_notifications_status_changes' => 'nullable|boolean',
        'team.smtp_notifications_database_backups' => 'nullable|boolean',
        'team.smtp_notifications_scheduled_tasks' => 'nullable|boolean',
        'team.use_instance_email_settings' => 'boolean',
        'team.resend_enabled' => 'nullable|boolean',
        'team.resend_api_key' => 'nullable',
    ];

    protected $validationAttributes = [
        'team.smtp_from_address' => 'From Address',
        'team.smtp_from_name' => 'From Name',
        'team.smtp_recipients' => 'Recipients',
        'team.smtp_host' => 'Host',
        'team.smtp_port' => 'Port',
        'team.smtp_encryption' => 'Encryption',
        'team.smtp_username' => 'Username',
        'team.smtp_password' => 'Password',
        'team.smtp_timeout' => 'Timeout',
        'team.resend_enabled' => 'Resend Enabled',
        'team.resend_api_key' => 'Resend API Key',
    ];

    public function mount()
    {
        $this->team = auth()->user()->currentTeam();
        ['sharedEmailEnabled' => $this->sharedEmailEnabled] = $this->team->limits;
        $this->emails = auth()->user()->email;
    }

    public function submitFromFields()
    {
        try {
            $this->resetErrorBag();
            $this->validate([
                'team.smtp_from_address' => 'required|email',
                'team.smtp_from_name' => 'required',
            ]);
            $this->team->save();
            refreshSession();
            $this->dispatch('success', 'Settings saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function sendTestNotification()
    {
        $this->team?->notify(new Test($this->emails));
        $this->dispatch('success', 'Test Email sent.');
    }

    public function instantSaveInstance()
    {
        try {
            if (! $this->sharedEmailEnabled) {
                throw new \Exception('Not allowed to change settings. Please upgrade your subscription.');
            }
            $this->team->smtp_enabled = false;
            $this->team->resend_enabled = false;
            $this->team->save();
            refreshSession();
            $this->dispatch('success', 'Settings saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSaveResend()
    {
        try {
            $this->team->smtp_enabled = false;
            $this->submitResend();
        } catch (\Throwable $e) {
            $this->team->smtp_enabled = false;

            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        try {
            $this->team->resend_enabled = false;
            $this->submit();
        } catch (\Throwable $e) {
            $this->team->smtp_enabled = false;

            return handleError($e, $this);
        }
    }

    public function saveModel()
    {
        $this->team->save();
        refreshSession();
        $this->dispatch('success', 'Settings saved.');
    }

    public function submit()
    {
        try {
            $this->resetErrorBag();
            if (! $this->team->use_instance_email_settings) {
                $this->validate([
                    'team.smtp_from_address' => 'required|email',
                    'team.smtp_from_name' => 'required',
                    'team.smtp_host' => 'required',
                    'team.smtp_port' => 'required|numeric',
                    'team.smtp_encryption' => 'nullable',
                    'team.smtp_username' => 'nullable',
                    'team.smtp_password' => 'nullable',
                    'team.smtp_timeout' => 'nullable',
                ]);
            }
            $this->team->save();
            refreshSession();
            $this->dispatch('success', 'Settings saved.');
        } catch (\Throwable $e) {
            $this->team->smtp_enabled = false;

            return handleError($e, $this);
        }
    }

    public function submitResend()
    {
        try {
            $this->resetErrorBag();
            $this->validate([
                'team.smtp_from_address' => 'required|email',
                'team.smtp_from_name' => 'required',
                'team.resend_api_key' => 'required',
            ]);
            $this->team->save();
            refreshSession();
            $this->dispatch('success', 'Settings saved.');
        } catch (\Throwable $e) {
            $this->team->resend_enabled = false;

            return handleError($e, $this);
        }
    }

    public function copyFromInstanceSettings()
    {
        $settings = \App\Models\InstanceSettings::get();
        if ($settings->smtp_enabled) {
            $team = currentTeam();
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
            refreshSession();
            $this->team = $team;
            $this->dispatch('success', 'Settings saved.');

            return;
        }
        if ($settings->resend_enabled) {
            $team = currentTeam();
            $team->update([
                'resend_enabled' => $settings->resend_enabled,
                'resend_api_key' => $settings->resend_api_key,
            ]);
            refreshSession();
            $this->team = $team;
            $this->dispatch('success', 'Settings saved.');

            return;
        }
        $this->dispatch('error', 'Instance SMTP/Resend settings are not enabled.');
    }

    public function render()
    {
        return view('livewire.notifications.email');
    }
}
