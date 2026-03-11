<?php

namespace App\Livewire\Settings;

use Livewire\Component;

class OauthRegistration extends Component
{
    public bool $is_oauth_registration_enabled = false;

    public function mount()
    {
        $settings = instanceSettings();
        $this->is_oauth_registration_enabled = (bool) $settings->is_oauth_registration_enabled;
    }

    public function save()
    {
        $settings = instanceSettings();
        $settings->is_oauth_registration_enabled = $this->is_oauth_registration_enabled;
        $settings->save();

        $this->dispatch('success', 'OAuth registration setting saved.');
    }

    public function render()
    {
        return view('livewire.settings.oauth-registration');
    }
}
