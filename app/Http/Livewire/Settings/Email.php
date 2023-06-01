<?php

namespace App\Http\Livewire\Settings;

use App\Models\InstanceSettings;
use Livewire\Component;

class Email extends Component
{
    public InstanceSettings $model;

    protected $rules = [
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
    ];
    public function mount($model)
    {
        //
    }
    public function render()
    {
        return view('livewire.settings.email');
    }
}
