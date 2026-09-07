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
            $carry["oauth_settings_map.$setting->provider.redirect_uri"] = 'nullable|string|max:2048';
            $carry["oauth_settings_map.$setting->provider.tenant"] = 'nullable';
            $carry["oauth_settings_map.$setting->provider.base_url"] = 'nullable|string|max:2048';
            $carry["oauth_settings_map.$setting->provider.custom_label"] = 'nullable|string|max:255';
            $carry["oauth_settings_map.$setting->provider.scopes"] = 'nullable|string|max:1000';
            $carry["oauth_settings_map.$setting->provider.use_pkce"] = 'boolean';
            $carry["oauth_settings_map.$setting->provider.clock_skew_seconds"] = 'nullable|integer|min:0|max:600';

            return $carry;
        }, []);
    }

    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('home');
        }
        $this->oauth_settings_map = OauthSetting::all()->sortBy('provider')->reduce(function ($carry, $setting) {
            $carry[$setting->provider] = $this->oauthSettingToArray($setting);

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

            $this->fillOauthSetting($oauth, $oauthData);

            if ($oauthData['enabled'] && ! $oauth->couldBeEnabled()) {
                $oauth->update(['enabled' => false]);
                throw new \Exception('OAuth settings are not complete for '.$oauth->provider.'.<br/>Please fill in all required fields.');
            }

            if ($oauthData['enabled'] && $oauth->isOidc() && ! in_array('openid', $oauth->scopeList(), true)) {
                $oauth->update(['enabled' => false]);
                throw new \Exception("OIDC scopes must include 'openid'.");
            }

            $oauth->save();
            $this->oauth_settings_map[$provider] = $this->oauthSettingToArray($oauth);

            $this->dispatch('success', 'OAuth settings for '.$oauth->provider.' updated successfully!');
        } else {
            $errors = [];
            foreach (array_values($this->oauth_settings_map) as $settingData) {
                $oauth = OauthSetting::find($settingData['id']);

                if (! $oauth) {
                    $errors[] = "OAuth setting for provider '{$settingData['provider']}' not found. It may have been deleted.";

                    continue;
                }

                $this->fillOauthSetting($oauth, $settingData);

                if ($settingData['enabled'] && ! $oauth->couldBeEnabled()) {
                    $oauth->enabled = false;
                    $errors[] = "OAuth settings are incomplete for '{$oauth->provider}'. Required fields are missing. The provider has been disabled.";
                }

                if ($oauth->enabled && $oauth->isOidc() && ! in_array('openid', $oauth->scopeList(), true)) {
                    $oauth->enabled = false;
                    $errors[] = "OIDC scopes must include 'openid'. The provider has been disabled.";
                }

                $oauth->save();
                $this->oauth_settings_map[$oauth->provider] = $this->oauthSettingToArray($oauth);
            }

            if (! empty($errors)) {
                $this->dispatch('error', implode('<br/>', $errors));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fillOauthSetting(OauthSetting $oauth, array $data): void
    {
        $oauth->fill([
            'enabled' => (bool) ($data['enabled'] ?? false),
            'client_id' => $data['client_id'] ?? null,
            'client_secret' => $data['client_secret'] ?? null,
            'redirect_uri' => $data['redirect_uri'] ?? null,
            'tenant' => $data['tenant'] ?? null,
            'base_url' => $data['base_url'] ?? null,
        ]);

        if ($oauth->isOidc()) {
            $oauth->fill([
                'custom_label' => $data['custom_label'] ?? null,
                'scopes' => $data['scopes'] ?? null,
                'use_pkce' => (bool) ($data['use_pkce'] ?? true),
                'clock_skew_seconds' => (int) ($data['clock_skew_seconds'] ?? 60),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function oauthSettingToArray(OauthSetting $setting): array
    {
        return [
            'id' => $setting->id,
            'provider' => $setting->provider,
            'enabled' => $setting->enabled,
            'client_id' => $setting->client_id,
            'client_secret' => $setting->client_secret,
            'redirect_uri' => $setting->redirect_uri,
            'tenant' => $setting->tenant,
            'base_url' => $setting->base_url,
            'custom_label' => $setting->custom_label,
            'scopes' => $setting->scopes ?: 'openid email profile',
            'use_pkce' => $setting->use_pkce ?? true,
            'clock_skew_seconds' => $setting->clock_skew_seconds ?? 60,
        ];
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

        if (in_array($provider, ['authentik', 'clerk', 'oidc'], true)) {
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
