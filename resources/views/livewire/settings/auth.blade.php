<div>
    <form wire:submit='submit' class="flex flex-col">
        <div class="flex flex-col">
            <div class="flex items-center gap-2">
                <h2>Authentication</h2>
                <x-forms.button type="submit">
                    Save
                </x-forms.button>
            </div>
        </div>
        <div class="flex flex-col gap-2 pt-4">
            @foreach ($oauth_settings_map as $oauth_setting)
                <div class="p-4 border dark:border-coolgray-300">
                    <h3>{{ucfirst($oauth_setting->provider)}} Oauth</h3>
                    <div class="w-32">
                        <x-forms.checkbox instantSave id="oauth_settings_map.{{$oauth_setting->provider}}.enabled" label="Enabled" />
                    </div>
                    <div class="flex flex-col w-full gap-2 xl:flex-row">
                        <x-forms.input id="oauth_settings_map.{{$oauth_setting->provider}}.client_id" label="Client ID" />
                        <x-forms.input id="oauth_settings_map.{{$oauth_setting->provider}}.client_secret" type="password" label="Client Secret" />
                        <x-forms.input id="oauth_settings_map.{{$oauth_setting->provider}}.redirect_uri" label="Redirect URI" />
                        @if ($oauth_setting->provider == 'azure')
                            <x-forms.input id="oauth_settings_map.{{$oauth_setting->provider}}.tenant" label="Tenant" />
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </form>
</div>
