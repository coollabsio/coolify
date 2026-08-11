<?php

namespace App\Livewire;

use App\Models\OauthSetting;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class SettingsOauth extends Component
{
    use AuthorizesRequests;

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
            $carry[$setting->provider] = [
                'id' => $setting->id,
                'provider' => $setting->provider,
                'enabled' => $setting->enabled,
                'client_id' => $setting->client_id,
                'client_secret' => $setting->client_secret,
                'redirect_uri' => $setting->redirect_uri,
                'tenant' => $setting->tenant,
                'base_url' => $setting->base_url,
            ];

            return $carry;
        }, []);
    }

    private function updateOauthSettings(?string $provider = null)
    {
        if ($provider) {
            $oauthData = $this->oauth_settings_map[$provider];
            $oauth = OauthSetting::find($oauthData['id']);

            if (! $oauth) {
                throw new \Exception('OAuth setting for '.$provider.' not found. It may have been deleted.');
            }

            $oauth->fill([
                'enabled' => $oauthData['enabled'],
                'client_id' => $oauthData['client_id'],
                'client_secret' => $oauthData['client_secret'],
                'redirect_uri' => $oauthData['redirect_uri'],
                'tenant' => $oauthData['tenant'],
                'base_url' => $oauthData['base_url'],
            ]);

            if ($oauthData['enabled'] && ! $oauth->couldBeEnabled()) {
                $oauth->update(['enabled' => false]);
                throw new \Exception('OAuth settings are not complete for '.$oauth->provider.'.<br/>Please fill in all required fields.');
            }
            $oauth->save();

            // Update the array with fresh data
            $this->oauth_settings_map[$provider] = [
                'id' => $oauth->id,
                'provider' => $oauth->provider,
                'enabled' => $oauth->enabled,
                'client_id' => $oauth->client_id,
                'client_secret' => $oauth->client_secret,
                'redirect_uri' => $oauth->redirect_uri,
                'tenant' => $oauth->tenant,
                'base_url' => $oauth->base_url,
            ];

            $this->dispatch('success', 'OAuth settings for '.$oauth->provider.' updated successfully!');
        } else {
            $errors = [];
            foreach (array_values($this->oauth_settings_map) as $settingData) {
                $oauth = OauthSetting::find($settingData['id']);

                if (! $oauth) {
                    $errors[] = "OAuth setting for provider '{$settingData['provider']}' not found. It may have been deleted.";

                    continue;
                }

                $oauth->fill([
                    'enabled' => $settingData['enabled'],
                    'client_id' => $settingData['client_id'],
                    'client_secret' => $settingData['client_secret'],
                    'redirect_uri' => $settingData['redirect_uri'],
                    'tenant' => $settingData['tenant'],
                    'base_url' => $settingData['base_url'],
                ]);

                if ($settingData['enabled'] && ! $oauth->couldBeEnabled()) {
                    $oauth->enabled = false;
                    $errors[] = "OAuth settings are incomplete for '{$oauth->provider}'. Required fields are missing. The provider has been disabled.";
                }

                $oauth->save();

                // Update the array with fresh data
                $this->oauth_settings_map[$oauth->provider] = [
                    'id' => $oauth->id,
                    'provider' => $oauth->provider,
                    'enabled' => $oauth->enabled,
                    'client_id' => $oauth->client_id,
                    'client_secret' => $oauth->client_secret,
                    'redirect_uri' => $oauth->redirect_uri,
                    'tenant' => $oauth->tenant,
                    'base_url' => $oauth->base_url,
                ];
            }

            if (! empty($errors)) {
                $this->dispatch('error', implode('<br/>', $errors));
            }
        }
    }

    public function instantSave(string $provider)
    {
        try {
            $this->authorize('update', instanceSettings());
            $this->updateOauthSettings($provider);
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function toggleProvider(string $provider): mixed
    {
        try {
            $this->authorize('update', instanceSettings());

            if (! array_key_exists($provider, $this->oauth_settings_map)) {
                throw new \Exception('OAuth provider not found.');
            }

            $enabling = ! $this->oauth_settings_map[$provider]['enabled'];
            if ($enabling) {
                $this->validate($this->providerRules($provider));
            }

            $this->oauth_settings_map[$provider]['enabled'] = $enabling;
            $this->updateOauthSettings($provider);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    private function providerRules(string $provider): array
    {
        $prefix = "oauth_settings_map.$provider";
        $rules = [
            "$prefix.client_id" => 'required',
            "$prefix.client_secret" => 'required',
        ];

        if ($provider === 'azure') {
            $rules["$prefix.tenant"] = 'required';
        }

        if (in_array($provider, ['authentik', 'clerk'], true)) {
            $rules["$prefix.base_url"] = 'required';
        }

        return $rules;
    }

    public function submit()
    {
        try {
            $this->authorize('update', instanceSettings());
            $this->updateOauthSettings();
            $this->dispatch('success', 'Instance settings updated successfully!');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
