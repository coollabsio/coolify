<div>
    <x-slot:title>
        Settings | Coolify
    </x-slot>
    <x-settings.navbar />

    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <div class="sub-menu-wrapper">
            <a class="sub-menu-item {{ $selectedProvider === null ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
                href="{{ route('settings.oauth') }}"><span class="menu-item-label">General</span></a>
            @foreach ($oauth_settings_map as $provider => $oauth_setting)
                <a class="sub-menu-item {{ $selectedProvider === $provider ? 'menu-item-active' : '' }}"
                    {{ wireNavigate() }} href="{{ route('settings.oauth.provider', $provider) }}"><span
                        class="menu-item-label">{{ $oauth_setting['label'] }}</span></a>
            @endforeach
        </div>

        <form wire:submit='submit' class="flex flex-col w-full">
            @if ($selectedProvider === null)
                <div class="flex flex-col">
                    <div class="flex items-center gap-2 pb-2">
                        <h2>Authentication</h2>
                    </div>
                    <div class="pb-4">General authentication settings for your Coolify instance.</div>
                </div>
                <div class="flex flex-col gap-2 pt-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3>Registration</h3>
                            <x-helper
                                helper="When enabled, the normal registration page is hidden if at least one OAuth provider is enabled. OAuth providers can still create users if their provider-specific registration option allows it." />
                        </div>
                        <div class="w-full max-w-2xl">
                            <x-forms.checkbox id="disable_registration_when_oauth_enabled"
                                label="Disable password registration when OAuth is enabled"
                                instantSave="saveRegistrationPolicy" fullWidth />
                        </div>
                    </div>
                </div>
            @else
                @php
                    $oauth_setting = $oauth_settings_map[$selectedProvider] ?? null;
                @endphp

                @if ($oauth_setting)
                    <div class="flex flex-col">
                        <div class="flex items-center gap-2 pb-2">
                            <h2>{{ $oauth_setting['label'] }}</h2>
                            @if ($oauth_setting['enabled'])
                                <x-forms.button type="submit">
                                    Save
                                </x-forms.button>
                                <x-forms.button wire:click="toggleProvider('{{ $oauth_setting['provider'] }}')">
                                    Disable {{ $oauth_setting['label'] }}
                                </x-forms.button>
                            @else
                                <x-forms.button isHighlighted wire:click="toggleProvider('{{ $oauth_setting['provider'] }}')">
                                    Enable {{ $oauth_setting['label'] }}
                                </x-forms.button>
                            @endif
                        </div>
                        <div class="pb-4">OAuth configuration for {{ $oauth_setting['label'] }}.</div>
                    </div>
                    <div class="flex flex-col gap-2 pt-4">
                        <div>
                            <div class="flex flex-col w-full gap-2 xl:flex-row">
                                <x-forms.input id="oauth_settings_map.{{ $oauth_setting['provider'] }}.client_id"
                                    label="Client ID" />
                                <x-forms.input id="oauth_settings_map.{{ $oauth_setting['provider'] }}.client_secret"
                                    type="password" label="Client Secret" autocomplete="new-password" />
                                <x-forms.input id="oauth_settings_map.{{ $oauth_setting['provider'] }}.redirect_uri"
                                    placeholder="{{ route('auth.callback', $oauth_setting['provider']) }}"
                                    label="Redirect URI" />
                                @if ($oauth_setting['provider'] == 'azure')
                                    <x-forms.input id="oauth_settings_map.{{ $oauth_setting['provider'] }}.tenant"
                                        label="Tenant" />
                                @endif
                                @if ($oauth_setting['provider'] == 'google')
                                    <x-forms.input id="oauth_settings_map.{{ $oauth_setting['provider'] }}.tenant"
                                        helper="Optional parameter that supplies a hosted domain (HD) to Google, which<br>triggers a login hint to be displayed on the OAuth screen with this domain.<br><br><a class='underline dark:text-warning text-coollabs' href='https://developers.google.com/identity/openid-connect/openid-connect#hd-param' target='_blank'>Google Documentation</a>"
                                        label="Tenant" />
                                @endif
                                @if (
                                    $oauth_setting['provider'] == 'authentik' ||
                                        $oauth_setting['provider'] == 'clerk' ||
                                        $oauth_setting['provider'] == 'zitadel' ||
                                        $oauth_setting['provider'] == 'gitlab')
                                    <x-forms.input id="oauth_settings_map.{{ $oauth_setting['provider'] }}.base_url"
                                        label="Base URL" />
                                @endif
                                @if ($oauth_setting['provider'] == 'oidc')
                                    <x-forms.input id="oauth_settings_map.{{ $oauth_setting['provider'] }}.base_url"
                                        label="Issuer URL"
                                        helper="OpenID Provider issuer URL, for example https://example.okta.com. Coolify uses this URL to discover authorization, token, userinfo, and JWKS endpoints." />
                                @endif
                            </div>
                            @if ($oauth_setting['provider'] == 'oidc')
                                <div class="flex flex-col w-full gap-2 pt-2 xl:flex-row">
                                    <x-forms.input id="oauth_settings_map.{{ $oauth_setting['provider'] }}.custom_label"
                                        label="Login Button Label" placeholder="Login with SSO" />
                                    <x-forms.input id="oauth_settings_map.{{ $oauth_setting['provider'] }}.scopes"
                                        label="Scopes"
                                        helper="Must include openid. Common Okta scopes: openid email profile groups." />
                                    <x-forms.input
                                        id="oauth_settings_map.{{ $oauth_setting['provider'] }}.clock_skew_seconds"
                                        type="number" label="Clock Skew (seconds)" />
                                </div>
                                <div class="flex flex-col gap-2 pt-2">
                                    <div class="md:w-96">
                                        <x-forms.checkbox
                                            id="oauth_settings_map.{{ $oauth_setting['provider'] }}.allow_registration"
                                            label="Allow OIDC user creation"
                                            helper="When enabled, a successful OIDC login can create a Coolify user even if normal password registration is disabled." />
                                    </div>
                                    <div class="md:w-96">
                                        <x-forms.checkbox
                                            id="oauth_settings_map.{{ $oauth_setting['provider'] }}.require_email_verified"
                                            label="Require verified email" />
                                    </div>
                                    <div class="md:w-96">
                                        <x-forms.checkbox
                                            id="oauth_settings_map.{{ $oauth_setting['provider'] }}.use_pkce"
                                            label="Use PKCE" />
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            @endif
        </form>
    </div>
</div>
