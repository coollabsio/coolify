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
        'model.smtp_attributes.smtp_active' => 'nullable|boolean',
        'model.smtp_attributes.from_address' => 'required',
        'model.smtp_attributes.from_name' => 'required',
        'model.smtp_attributes.recipients' => 'required',
        'model.smtp_attributes.smtp_host' => 'required',
        'model.smtp_attributes.smtp_port' => 'required',
        'model.smtp_attributes.smtp_encryption' => 'nullable',
        'model.smtp_attributes.smtp_username' => 'nullable',
        'model.smtp_attributes.smtp_password' => 'nullable',
        'model.smtp_attributes.smtp_timeout' => 'nullable',
        'model.smtp_attributes.test_address' => 'nullable',
    ];
    protected $validationAttributes = [
        'model.smtp_attributes.from_address' => 'From Address',
        'model.smtp_attributes.from_name' => 'From Name',
        'model.smtp_attributes.recipients' => 'Recipients',
        'model.smtp_attributes.smtp_host' => 'Host',
        'model.smtp_attributes.smtp_port' => 'Port',
        'model.smtp_attributes.smtp_encryption' => 'Encryption',
        'model.smtp_attributes.smtp_username' => 'Username',
        'model.smtp_attributes.smtp_password' => 'Password',
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
        $this->saveModel();
    }
}
