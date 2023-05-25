<?php

namespace App\Http\Livewire\Notifications;

use App\Models\Server;
use App\Models\Team;
use Livewire\Component;

class EmailSettings extends Component
{
    public Team|Server $model;

    protected $rules = [
        'model.extra_attributes.smtp_active' => 'nullable|boolean',
        'model.extra_attributes.from_address' => 'nullable',
        'model.extra_attributes.from_name' => 'nullable',
        'model.extra_attributes.recipients' => 'nullable',
        'model.extra_attributes.smtp_host' => 'nullable',
        'model.extra_attributes.smtp_port' => 'nullable',
        'model.extra_attributes.smtp_encryption' => 'nullable',
        'model.extra_attributes.smtp_username' => 'nullable',
        'model.extra_attributes.smtp_password' => 'nullable',
        'model.extra_attributes.smtp_timeout' => 'nullable',
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
        'model.extra_attributes.smtp_timeout' => 'Timeout',
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

    }
    public function render()
    {
        return view('livewire.notifications.email-settings');
    }
}
