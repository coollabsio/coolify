<div>
    <x-slot:title>
        Authentication | Coolify
    </x-slot>

    <x-settings.navbar />

    <div
        class="application-settings-workspace mx-auto grid w-full max-w-[1180px] min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
        <aside class="application-settings-navigation min-w-0 xl:sticky xl:top-26 xl:self-start"
            x-data="{ activeProvider: location.hash.slice(1).replace('-oauth-section', '') || '{{ $oauth_settings_map[0]['provider'] ?? '' }}' }"
            @hashchange.window="activeProvider = location.hash.slice(1).replace('-oauth-section', '')">
            <nav aria-label="OAuth providers"
                class="grid max-h-[calc(100vh-8rem)] grid-cols-2 gap-0.5 overflow-y-auto border-y border-neutral-200 py-4 sm:grid-cols-3 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
                <div class="nav-section hidden xl:block">Providers</div>
                @foreach ($oauth_settings_map as $oauth_setting)
                    @php
                        $provider = $oauth_setting['provider'];
                        $providerLabel = str($provider)->headline();
                    @endphp
                    <a href="#{{ $provider }}-oauth-section" class="menu-item"
                        :class="{ 'menu-item-active': activeProvider === '{{ $provider }}' }"
                        @click="activeProvider = '{{ $provider }}'">
                        <x-reicon name="keys" class="menu-item-icon" />
                        <span class="menu-item-label">{{ $providerLabel }}</span>
                    </a>
                @endforeach
            </nav>
        </aside>
        <form wire:submit="submit" class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            <x-unsaved-bar action="submit" />
            @foreach ($oauth_settings_map as $oauth_setting)
                @php
                    $provider = $oauth_setting['provider'];
                    $providerLabel = str($provider)->headline();
                @endphp

                <x-application.settings-section id="{{ $provider }}-oauth-section" class="scroll-mt-28"
                    title="{{ $providerLabel }}">
                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.listbox id="oauth_settings_map.{{ $provider }}.enabled"
                            label="Provider status" :options="[
                                ['value' => true, 'label' => 'Enabled'],
                                ['value' => false, 'label' => 'Disabled'],
                            ]" />

                        <x-forms.input id="oauth_settings_map.{{ $provider }}.redirect_uri"
                            placeholder="{{ route('auth.callback', $provider) }}" label="Redirect URI" />

                        <x-forms.input id="oauth_settings_map.{{ $provider }}.client_id"
                            label="Client ID" />
                        <x-forms.input id="oauth_settings_map.{{ $provider }}.client_secret"
                            type="password" label="Client secret" autocomplete="new-password" />

                        @if ($provider === 'azure')
                            <x-forms.input id="oauth_settings_map.{{ $provider }}.tenant"
                                label="Tenant" />
                        @endif

                        @if ($provider === 'google')
                            <x-forms.input id="oauth_settings_map.{{ $provider }}.tenant"
                                helper="Optional hosted domain supplied to Google as a login hint."
                                label="Hosted domain" />
                        @endif

                        @if (in_array($provider, ['authentik', 'clerk', 'zitadel', 'gitlab'], true))
                            <x-forms.input id="oauth_settings_map.{{ $provider }}.base_url"
                                label="Base URL" />
                        @endif
                    </div>
                </x-application.settings-section>
            @endforeach
        </form>
    </div>
</div>
