<div>
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
                    @if ($is_registration_enabled)
                        <div class="md:w-96" wire:key="registration-enabled">
                            <x-forms.checkbox instantSave id="is_registration_enabled"
                                helper="Allow users to self-register. If disabled, only administrators can create accounts."
                                label="Registration Allowed" />
                        </div>
                    @else
                        <div class="flex items-center justify-between gap-2 md:w-96"
                            wire:key="registration-disabled">
                            <label class="flex items-center gap-2">
                                Registration Allowed
                                <x-helper
                                    helper="Allow users to self-register. If disabled, only administrators can create accounts.">
                                </x-helper>
                            </label>
                            <x-modal-confirmation title="Enable Registration?" buttonTitle="Enable" isErrorButton
                                submitAction="toggleRegistration" :actions="[
                                    'Enables registration for everyone',
                                ]"
                                warningMessage="Enabling registration allows anyone to create an account on this instance. Make sure you understand the implications before proceeding."
                                confirmationText="ENABLE REGISTRATION"
                                confirmationLabel="Please type the confirmation text to enable registration."
                                shortConfirmationLabel="Confirmation text" />
                        </div>
                    @endif
                    <div class="md:w-96">
                        <x-forms.checkbox instantSave id="is_oauth_registration_enabled"
                            helper="Allow users to self-register via OAuth providers (GitHub, GitLab, Google, etc.) even when general registration is disabled. Useful when you want to restrict sign-up to users with a specific OAuth identity provider (e.g. Authentik, Keycloak)."
                            label="OAuth Registration Allowed" />
                    </div>
                    <div class="md:w-96">
                        <x-forms.checkbox instantSave id="do_not_track"
                            helper="Opt out of anonymous usage tracking. When enabled, this instance will not report to coolify.io's installation count and will not send error reports to help improve Coolify."
                            label="Do Not Track" />
                    </div>
                    <h4 class="pt-4">DNS Settings</h4>
                    <div class="md:w-96">
                        <x-forms.checkbox instantSave id="is_dns_validation_enabled"
                            helper="Verify that custom domains are correctly configured in DNS before deployment. Prevents deployment failures from DNS misconfigurations."
                            label="DNS Validation" />
                    </div>
                    <div class="md:w-96">
                        <x-forms.input id="custom_dns_servers" label="Custom DNS Servers"
                            placeholder="1.1.1.1,8.8.8.8"
                            helper="Custom DNS servers for your Coolify instance. Comma separated list of IP addresses." />
                    </div>
                    <h4 class="pt-4">API</h4>
                    <div class="md:w-96">
                        <x-forms.checkbox instantSave id="is_api_enabled" label="Enable API"
                            helper="Enable the Coolify API. This allows you to manage your Coolify instance via the API." />
                    </div>
                    @if ($is_api_enabled)
                        <div class="md:w-96">
                            <x-forms.input id="allowed_ips" label="Allowed IPs"
                                placeholder="0.0.0.0"
                                helper="Restrict API access to specific IP addresses or subnets. Comma separated. Use 0.0.0.0 to allow all." />
                        </div>
                    @endif
                </div>
            </form>
        </div>
</div>
