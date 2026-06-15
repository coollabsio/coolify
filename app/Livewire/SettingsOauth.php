<?php

namespace App\Livewire;

use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class SettingsOauth extends Component
{
    public InstanceSettings $settings;

    public $oauth_settings_map;

    public ?string $selectedProvider = null;

    public bool $disable_registration_when_oauth_enabled = false;

    protected function rules(): array
    {
        return $this->validationRules();
    }

    private function validationRules(?string $provider = null): array
    {
        $rules = OauthSetting::all()->reduce(function ($carry, $setting) use ($provider) {
            if ($provider !== null && $setting->provider !== $provider) {
                return $carry;
            }

            $carry["oauth_settings_map.$setting->provider.enabled"] = 'required|boolean';
            $carry["oauth_settings_map.$setting->provider.client_id"] = 'nullable|string';
            $carry["oauth_settings_map.$setting->provider.client_secret"] = 'nullable|string';
            $carry["oauth_settings_map.$setting->provider.redirect_uri"] = 'nullable|string|max:2048|url:http,https';
            $carry["oauth_settings_map.$setting->provider.tenant"] = 'nullable|string';
            $carry["oauth_settings_map.$setting->provider.base_url"] = 'nullable|string|max:2048|url:http,https';
            $carry["oauth_settings_map.$setting->provider.custom_label"] = 'nullable|string|max:255';
            $carry["oauth_settings_map.$setting->provider.scopes"] = 'nullable|string|max:1000';
            $carry["oauth_settings_map.$setting->provider.allow_registration"] = 'boolean';
            $carry["oauth_settings_map.$setting->provider.require_email_verified"] = 'boolean';
            $carry["oauth_settings_map.$setting->provider.use_pkce"] = 'boolean';
            $carry["oauth_settings_map.$setting->provider.clock_skew_seconds"] = 'nullable|integer|min:0|max:600';

            return $carry;
        }, []);

        if ($provider === null) {
            $rules['disable_registration_when_oauth_enabled'] = 'boolean';
        }

        return $rules;
    }

    public function mount(?string $provider = null)
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('home');
        }

        $this->settings = instanceSettings();
        $this->selectedProvider = $provider;
        $this->disable_registration_when_oauth_enabled = (bool) $this->settings->disable_registration_when_oauth_enabled;
        $this->oauth_settings_map = OauthSetting::all()->sortBy('provider')->reduce(function ($carry, $setting) {
            $carry[$setting->provider] = $this->oauthSettingToArray($setting);

            return $carry;
        }, []);

        if ($this->selectedProvider !== null && ! array_key_exists($this->selectedProvider, $this->oauth_settings_map)) {
            abort(404);
        }
    }

    private function updateOauthSettings(?string $provider = null): void
    {
        $this->validate($this->validationRules($provider));

        if ($provider) {
            $oauthData = $this->oauth_settings_map[$provider];
            $oauth = OauthSetting::find($oauthData['id']);

            if (! $oauth) {
                throw new \Exception('OAuth setting for '.$provider.' not found. It may have been deleted.');
            }

            $this->fillOauthSetting($oauth, $oauthData);
            $this->ensureProviderCanBeEnabled($oauth);
            $oauth->save();

            $this->oauth_settings_map[$provider] = $this->oauthSettingToArray($oauth);

            $this->dispatch('success', 'OAuth settings for '.$oauth->provider.' updated successfully!');

            return;
        }

        $errors = [];
        foreach (array_values($this->oauth_settings_map) as $settingData) {
            $oauth = OauthSetting::find($settingData['id']);

            if (! $oauth) {
                $errors[] = "OAuth setting for provider '{$settingData['provider']}' not found. It may have been deleted.";

                continue;
            }

            $this->fillOauthSetting($oauth, $settingData);

            if ($oauth->enabled && ! $oauth->couldBeEnabled()) {
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

        instanceSettings()->update([
            'disable_registration_when_oauth_enabled' => $this->disable_registration_when_oauth_enabled,
        ]);

        if (! empty($errors)) {
            $this->dispatch('error', implode('<br/>', $errors));
        }
    }

    private function fillOauthSetting(OauthSetting $oauth, array $data): void
    {
        $oauth->fill([
            'enabled' => (bool) ($data['enabled'] ?? false),
            'client_id' => $data['client_id'] ?? null,
            'client_secret' => $data['client_secret'] ?? null,
            'redirect_uri' => $this->nullableString($data['redirect_uri'] ?? null),
            'tenant' => $data['tenant'] ?? null,
            'base_url' => $this->nullableString($data['base_url'] ?? null),
            'custom_label' => $data['custom_label'] ?? null,
            'scopes' => $data['scopes'] ?? null,
            'allow_registration' => (bool) ($data['allow_registration'] ?? false),
            'require_email_verified' => (bool) ($data['require_email_verified'] ?? true),
            'use_pkce' => (bool) ($data['use_pkce'] ?? true),
            'clock_skew_seconds' => (int) ($data['clock_skew_seconds'] ?? 60),
        ]);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function ensureProviderCanBeEnabled(OauthSetting $oauth): void
    {
        if (! $oauth->enabled) {
            return;
        }

        if (! $oauth->couldBeEnabled()) {
            $oauth->update(['enabled' => false]);
            throw new \Exception('OAuth settings are not complete for '.$oauth->provider.'.<br/>Please fill in all required fields.');
        }

        if ($oauth->isOidc() && ! in_array('openid', $oauth->scopeList(), true)) {
            $oauth->update(['enabled' => false]);
            throw new \Exception("OIDC scopes must include 'openid'.");
        }
    }

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
            'allow_registration' => $setting->allow_registration,
            'require_email_verified' => $setting->require_email_verified ?? true,
            'use_pkce' => $setting->use_pkce ?? true,
            'clock_skew_seconds' => $setting->clock_skew_seconds ?? 60,
            'label' => $this->providerLabel($setting->provider),
        ];
    }

    public function providerLabel(string $provider): string
    {
        return match ($provider) {
            'oidc' => 'OpenID Connect',
            'gitlab' => 'GitLab',
            default => str($provider)->headline()->toString(),
        };
    }

    public function instantSave(string $provider)
    {
        try {
            $this->updateOauthSettings($provider);
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function toggleProvider(string $provider)
    {
        try {
            if (! array_key_exists($provider, $this->oauth_settings_map)) {
                abort(404);
            }

            if (! (bool) $this->oauth_settings_map[$provider]['enabled']) {
                $this->validateProviderCanBeEnabled($provider);
            }

            $this->oauth_settings_map[$provider]['enabled'] = ! (bool) $this->oauth_settings_map[$provider]['enabled'];
            $this->updateOauthSettings($provider);
        } catch (\Exception $e) {
            $oauth = OauthSetting::where('provider', $provider)->first();
            if ($oauth) {
                $this->oauth_settings_map[$provider] = $this->oauthSettingToArray($oauth);
            }

            return handleError($e, $this);
        }
    }

    private function validateProviderCanBeEnabled(string $provider): void
    {
        $this->validate($this->validationRules($provider));

        $oauth = OauthSetting::find($this->oauth_settings_map[$provider]['id']);
        if (! $oauth) {
            throw new \Exception('OAuth setting for '.$provider.' not found. It may have been deleted.');
        }

        $this->fillOauthSetting($oauth, [
            ...$this->oauth_settings_map[$provider],
            'enabled' => true,
        ]);

        if (! $oauth->couldBeEnabled()) {
            throw new \Exception('OAuth settings are not complete for '.$oauth->provider.'.<br/>Please fill in all required fields.');
        }

        if ($oauth->isOidc() && ! in_array('openid', $oauth->scopeList(), true)) {
            throw new \Exception("OIDC scopes must include 'openid'.");
        }
    }

    public function saveRegistrationPolicy(): void
    {
        $this->validate([
            'disable_registration_when_oauth_enabled' => 'boolean',
        ]);

        instanceSettings()->update([
            'disable_registration_when_oauth_enabled' => $this->disable_registration_when_oauth_enabled,
        ]);

        $this->dispatch('success', 'Authentication settings updated successfully!');
    }

    public function submit(): void
    {
        try {
            $this->updateOauthSettings($this->selectedProvider);

            if ($this->selectedProvider === null) {
                $this->dispatch('success', 'Instance settings updated successfully!');
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            if ($this->selectedProvider !== null) {
                $oauth = OauthSetting::where('provider', $this->selectedProvider)->first();
                if ($oauth) {
                    $this->oauth_settings_map[$this->selectedProvider] = $this->oauthSettingToArray($oauth);
                }
            }

            handleError($e, $this);
        }
    }
}
