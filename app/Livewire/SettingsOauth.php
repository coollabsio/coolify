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
            $carry["oauth_settings_map.$setting->provider.base_url"] = 'nullable';

            return $carry;
        }, []);
    }

    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('home');
        }
        $this->oauth_settings_map = OauthSetting::all()->sortBy('provider')->reduce(function ($carry, $setting) {
            $carry[$setting->provider] = $setting;

            return $carry;
        }, []);
    }

    private function updateOauthSettings(?string $provider = null)
    {
        if ($provider) {
            $oauth = $this->oauth_settings_map[$provider];
            if (! $oauth->couldBeEnabled()) {
                $oauth->update(['enabled' => false]);
                throw new \Exception('OAuth settings are not complete for '.$oauth->provider.'.<br/>Please fill in all required fields.');
            }
            $oauth->save();
            $this->dispatch('success', 'OAuth settings for '.$oauth->provider.' updated successfully!');
        } else {
            foreach (array_values($this->oauth_settings_map) as &$setting) {
                $setting->save();
            }
        }
    }

    public function instantSave(string $provider)
    {
        try {
            $this->updateOauthSettings($provider);
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        $this->updateOauthSettings();
        $this->dispatch('success', 'Instance settings updated successfully!');
    }
}
