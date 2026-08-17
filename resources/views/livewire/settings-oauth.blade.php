<div>
    <x-slot:title>
        Authentication | Coolify
    </x-slot>

    <x-settings.layout>
        <x-slot:submenu>
        <div
            x-data="{ activeProvider: location.hash.slice(1).replace('-oauth-section', '') || '{{ $oauth_settings_map[0]['provider'] ?? '' }}' }"
            @hashchange.window="activeProvider = location.hash.slice(1).replace('-oauth-section', '')">
            <nav aria-label="OAuth providers"
                class="grid gap-0.5 py-1">
                @foreach ($oauth_settings_map as $oauth_setting)
                    @php
                        $provider = $oauth_setting['provider'];
                        $providerLabel = str($provider)->headline();
                    @endphp
                    <a href="#{{ $provider }}-oauth-section" class="menu-item min-h-8! py-1! text-[12px]!"
                        :class="{ 'menu-item-active': activeProvider === '{{ $provider }}' }"
                        @click.prevent="activeProvider = '{{ $provider }}'; history.replaceState(null, '', '#{{ $provider }}-oauth-section'); window.scrollToSettingsSection?.('{{ $provider }}-oauth-section')">
                        <span class="menu-item-icon bg-current"
                            style="mask: url('{{ asset('svgs/' . $provider . '.svg') }}') center / contain no-repeat; -webkit-mask: url('{{ asset('svgs/' . $provider . '.svg') }}') center / contain no-repeat;"></span>
                        <span class="menu-item-label">{{ $providerLabel }}</span>
                    </a>
                @endforeach
            </nav>
        </div>
        </x-slot:submenu>
        <form wire:submit="submit" class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            <x-unsaved-bar action="submit" />
            @foreach ($oauth_settings_map as $oauth_setting)
                @php
                    $provider = $oauth_setting['provider'];
                    $providerLabel = str($provider)->headline();
                @endphp

                <x-application.settings-section id="{{ $provider }}-oauth-section" class="scroll-mt-28"
                    title="{{ $providerLabel }}">
                    <x-slot:actions>
                        <div x-data="{ enabled: @js((bool) $oauth_setting['enabled']), provider: @js($provider) }">
                            <x-forms.button type="button" :isHighlighted="!$oauth_setting['enabled']"
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
                        <x-forms.input id="oauth_settings_map.{{ $provider }}.redirect_uri"
                            placeholder="{{ route('auth.callback', $provider) }}" label="Redirect URI" />

                        <x-forms.input id="oauth_settings_map.{{ $provider }}.client_id"
                            label="Client ID" required />
                        <x-forms.input id="oauth_settings_map.{{ $provider }}.client_secret"
                            type="password" label="Client secret" autocomplete="new-password" required />

                        @if ($provider === 'azure')
                            <x-forms.input id="oauth_settings_map.{{ $provider }}.tenant"
                                label="Tenant" required />
                        @endif

                        @if ($provider === 'google')
                            <x-forms.input id="oauth_settings_map.{{ $provider }}.tenant"
                                helper="Optional hosted domain supplied to Google as a login hint."
                                label="Hosted domain" />
                        @endif

                        @if (in_array($provider, ['authentik', 'clerk', 'zitadel', 'gitlab'], true))
                            <x-forms.input id="oauth_settings_map.{{ $provider }}.base_url"
                                label="Base URL" :required="in_array($provider, ['authentik', 'clerk'], true)" />
                        @endif
                    </div>
                </x-application.settings-section>
            @endforeach
        </form>
    </x-settings.layout>
</div>
