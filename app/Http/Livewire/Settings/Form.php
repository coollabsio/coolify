<?php

namespace App\Http\Livewire\Settings;

use App\Models\InstanceSettings as ModelsInstanceSettings;
use Livewire\Component;

class Form extends Component
{
    public ModelsInstanceSettings $settings;
    public $do_not_track;
    public $is_auto_update_enabled;
    public $is_registration_enabled;
    public $is_https_forced;

    protected $rules = [
        'settings.fqdn' => 'nullable',
        'settings.wildcard_domain' => 'nullable',
        'settings.public_port_min' => 'required',
        'settings.public_port_max' => 'required',
    ];
    public function mount()
    {
        $this->do_not_track = $this->settings->do_not_track;
        $this->is_auto_update_enabled = $this->settings->is_auto_update_enabled;
        $this->is_registration_enabled = $this->settings->is_registration_enabled;
        $this->is_https_forced = $this->settings->is_https_forced;
    }
    public function instantSave()
    {
        $this->settings->do_not_track = $this->do_not_track;
        $this->settings->is_auto_update_enabled = $this->is_auto_update_enabled;
        $this->settings->is_registration_enabled = $this->is_registration_enabled;
        $this->settings->is_https_forced = $this->is_https_forced;
        $this->settings->save();
        $this->emit('saved', 'Settings updated!');
    }
    public function submit()
    {
        $this->resetErrorBag();
        if ($this->settings->public_port_min > $this->settings->public_port_max) {
            $this->addError('settings.public_port_min', 'The minimum port must be lower than the maximum port.');
            return;
        }
        $this->validate();
        $this->settings->save();
    }
}
