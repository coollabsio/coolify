is_registration_enabled<div>
    <x-slot:title>
        Advanced Settings | Coolify
        </x-slot>
        <x-settings.navbar />
        <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }"
            class="flex flex-col h-full gap-8 sm:flex-row">
            <x-settings.sidebar activeMenu="advanced" />
            <form wire:submit='submit' class="flex flex-col w-full">
                <div class="flex items-center gap-2">
                    <h2>Advanced</h2>
                    <x-forms.button type="submit">
                        Save
                    </x-forms.button>
                </div>
                <div class="pb-4">Advanced settings for your Coolify instance.</div>


                <div class="flex flex-col gap-1">
                    <div class="md:w-96">
                        <x-forms.checkbox instantSave id="is_registration_enabled"
                            helper="Allow users to self-register. If disabled, only administrators can create accounts."
                            label="Registration Allowed" />
                    </div>
                    <div class="md:w-96">
                        <x-forms.checkbox instantSave id="is_oauth_registration_enabled" label="Allow OAuth2 Self-Registration" />
                    </div>
                    <div class="md:w-96">
                        <x-forms.checkbox instantSave id="do_not_track"
                            helper="Opt out of reporting this instance to coolify.io's installation count. No other data is collected."
                            label="Do Not Track" />
                    </div>
                    <h4 class="pt-4">DNS Settings</h4>
                    <div class="md:w-96">
                        <x-forms.checkbox instantSave id="is_dns_validation_enabled"
                            helper="Verify that custom domains are correctly configured in DNS before deployment. Prevents deployment failures from DNS misconfigurations."
                            label="DNS Validation" />
                    </div>


                    <x-forms.input id="custom_dns_servers" label="Custom DNS Servers"
