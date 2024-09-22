<?php

namespace App\Livewire;

use App\Models\OauthSetting;
use Livewire\Component;

class SettingsOauth extends Component
{
    public $oauth_settings_map;

    protected function rules()
    {
        return OauthSetting::all()->reduce(function ($carry, $setting) {
            $carry["oauth_settings_map.$setting->provider.enabled"] = 'required';
            $carry["oauth_settings_map.$setting->provider.client_id"] = 'nullable';
            $carry["oauth_settings_map.$setting->provider.client_secret"] = 'nullable';
            $carry["oauth_settings_map.$setting->provider.redirect_uri"] = 'nullable';
            $carry["oauth_settings_map.$setting->provider.tenant"] = 'nullable';

            return $carry;
        }, []);
    }

    public function mount()
    {
        $this->oauth_settings_map = OauthSetting::all()->sortBy('provider')->reduce(function ($carry, $setting) {
            $carry[$setting->provider] = $setting;

            return $carry;
        }, []);
    }

    private function updateOauthSettings()
    {
        foreach (array_values($this->oauth_settings_map) as &$setting) {
            $setting->save();
        }
    }

    public function instantSave()
    {
        $this->updateOauthSettings();
    }

    public function submit()
    {
        $this->updateOauthSettings();
        $this->dispatch('success', 'Instance settings updated successfully!');
    }
}
