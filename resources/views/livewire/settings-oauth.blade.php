<div>
    <x-slot:title>
        Authentication | Coolify
    </x-slot>

    <x-settings.layout>
        <x-slot:submenu>
            <div
                x-data="{ activeProvider: location.hash.slice(1).replace('-oauth-section', '') || @js($selectedProvider ?? array_key_first($oauth_settings_map)) }"
                @hashchange.window="activeProvider = location.hash.slice(1).replace('-oauth-section', '')">
                <nav aria-label="OAuth providers" class="grid gap-0.5 py-1">
                    @foreach ($oauth_settings_map as $provider => $oauth_setting)
                        <a href="#{{ $provider }}-oauth-section" class="menu-item min-h-8! py-1! text-[12px]!"
                            :class="{ 'menu-item-active': activeProvider === '{{ $provider }}' }"
                            @click.prevent="activeProvider = '{{ $provider }}'; history.replaceState(null, '', '#{{ $provider }}-oauth-section'); window.scrollToSettingsSection?.('{{ $provider }}-oauth-section')">
                            <span class="menu-item-icon bg-current"
                                style="mask: url('{{ asset('svgs/' . $provider . '.svg') }}') center / contain no-repeat; -webkit-mask: url('{{ asset('svgs/' . $provider . '.svg') }}') center / contain no-repeat;"></span>
                            <span class="menu-item-label">{{ $oauth_setting['label'] }}</span>
                        </a>
                    @endforeach
                </nav>
            </div>
        </x-slot:submenu>

        <form wire:submit="submit" class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            <x-unsaved-bar action="submit" />

            <x-application.settings-section title="Registration"
                description="Control password registration when an OAuth provider is available.">
                <x-forms.checkbox canGate="update" :canResource="$settings"
                    id="disable_registration_when_oauth_enabled"
                    label="Disable password registration when OAuth is enabled"
                    helper="OAuth providers can still create users when registration is enabled for that provider."
                    instantSave="saveRegistrationPolicy" />
            </x-application.settings-section>

            @foreach ($oauth_settings_map as $provider => $oauth_setting)
                <x-application.settings-section id="{{ $provider }}-oauth-section" class="scroll-mt-28"
                    title="{{ $oauth_setting['label'] }}">
                    <x-slot:actions>
                        <div x-data="{ enabled: @js((bool) $oauth_setting['enabled']), provider: @js($provider) }">
                            <x-forms.button canGate="update" :canResource="$settings" type="button"
                                :isHighlighted="!$oauth_setting['enabled']"
                                x-on:click="
                                    if (!enabled) {
                                        const invalidField = [...$el.closest('section').querySelectorAll('[required]')]
                                            .find(field => !field.checkValidity());
                                        if (invalidField) { invalidField.reportValidity(); return; }
                                    }
                                    $wire.toggleProvider(provider);
                                ">
                                {{ $oauth_setting['enabled'] ? 'Disable' : 'Enable' }}
                            </x-forms.button>
                        </div>
                    </x-slot:actions>

                    <div class="grid gap-4 lg:grid-cols-2">
                        @if ($provider === 'oidc')
                            <x-forms.input canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.redirect_uri"
                                placeholder="{{ route('auth.callback', $provider) }}" label="Redirect URI" />
                            <x-forms.input canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.base_url" label="Issuer URL" required
                                helper="OpenID Provider issuer URL, for example https://example.okta.com. Coolify uses it to discover the authorization, token, userinfo, and JWKS endpoints." />
                            <x-forms.input canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.client_id" label="Client ID" required />
                            <x-forms.input canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.client_secret" type="password"
                                label="Client secret" autocomplete="new-password" required />
                            <x-forms.input canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.scopes" label="Scopes"
                                helper="Must include openid. Common scopes are openid email profile groups." />
                            <x-forms.input canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.clock_skew_seconds" type="number"
                                label="Clock skew (seconds)" />
                            <div class="lg:col-span-2">
                                <x-forms.input canGate="update" :canResource="$settings"
                                    id="oauth_settings_map.{{ $provider }}.custom_label" label="Login button label"
                                    placeholder="Login with SSO" />
                            </div>
                        @else
                            <x-forms.input canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.redirect_uri"
                                placeholder="{{ route('auth.callback', $provider) }}" label="Redirect URI" />
                            <x-forms.input canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.client_id" label="Client ID" required />
                            <x-forms.input canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.client_secret" type="password"
                                label="Client secret" autocomplete="new-password" required />
                        @endif

                        @if ($provider === 'azure')
                            <x-forms.input canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.tenant" label="Tenant" required />
                        @endif

                        @if ($provider === 'google')
                            <x-forms.input canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.tenant"
                                helper="Optional hosted domain supplied to Google as a login hint."
                                label="Hosted domain" />
                        @endif

                        @if (in_array($provider, ['authentik', 'clerk', 'zitadel', 'gitlab'], true))
                            <x-forms.input canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.base_url" label="Base URL"
                                :required="in_array($provider, ['authentik', 'clerk'], true)" />
                        @endif

                    </div>

                    <div class="mt-4 grid gap-3 lg:grid-cols-2">
                        @if ($provider === 'oidc')
                            <x-forms.checkbox canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.allow_registration"
                                label="Allow OIDC user creation"
                                helper="Allow a successful OIDC login to create a user when password registration is disabled." />
                            <x-forms.checkbox canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.require_email_verified"
                                label="Require verified email" />
                            <x-forms.checkbox canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.use_pkce" label="Use PKCE" />
                        @endif
                        <x-forms.checkbox canGate="update" :canResource="$settings"
                            id="oauth_settings_map.{{ $provider }}.auto_join_root_team"
                            label="Auto-join new users to Root team"
                            helper="Add newly-created OAuth users to the Root team as members without creating a personal team." />
                    </div>
                </x-application.settings-section>
            @endforeach
        </form>
    </x-settings.layout>
</div>
