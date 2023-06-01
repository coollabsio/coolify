<?php

namespace App\Http\Livewire\Notifications;

use App\Models\Server;
use App\Models\Team;
use App\Notifications\TestNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Component;

class EmailSettings extends Component
{
    public Team|Server $model;

    protected $rules = [
        'model.extra_attributes.smtp_active' => 'nullable|boolean',
        'model.extra_attributes.from_address' => 'required|email',
        'model.extra_attributes.from_name' => 'required',
        'model.extra_attributes.recipients' => 'required',
        'model.extra_attributes.smtp_host' => 'required',
        'model.extra_attributes.smtp_port' => 'required',
        'model.extra_attributes.smtp_encryption' => 'nullable',
        'model.extra_attributes.smtp_username' => 'nullable',
        'model.extra_attributes.smtp_password' => 'nullable',
        'model.extra_attributes.smtp_timeout' => 'nullable',
        'model.extra_attributes.test_notification_email' => 'nullable|email',
    ];
    protected $validationAttributes = [
        'model.extra_attributes.from_address' => 'From Address',
        'model.extra_attributes.from_name' => 'From Name',
        'model.extra_attributes.recipients' => 'Recipients',
        'model.extra_attributes.smtp_host' => 'Host',
        'model.extra_attributes.smtp_port' => 'Port',
        'model.extra_attributes.smtp_encryption' => 'Encryption',
        'model.extra_attributes.smtp_username' => 'Username',
        'model.extra_attributes.smtp_password' => 'Password',
    ];
    public function mount($model)
    {
        //
    }
    public function submit()
    {
        $this->resetErrorBag();
        $this->validate();
        $this->saveModel();
    }
    private function saveModel()
    {
        $this->model->save();
        if (is_a($this->model, Team::class)) {
            session(['currentTeam' => $this->model]);
        }
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
