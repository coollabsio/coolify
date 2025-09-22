<div>
    <x-slot:title>
        Advanced Settings | Coolify
    </x-slot>
    <x-settings.navbar />
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }" class="flex flex-col h-full gap-8 sm:flex-row">
        <x-settings.sidebar activeMenu="advanced" />
        <form wire:submit='submit' class="flex flex-col">
            <div class="flex items-center gap-2">
                <h2>Advanced</h2>
                <x-forms.button type="submit">
                    Save
                </x-forms.button>
            </div>
            <div class="pb-4">Advanced settings for your Coolify instance.</div>

            <div class="flex flex-col gap-1 md:w-96">
                <x-forms.checkbox instantSave id="is_registration_enabled"
                    helper="If enabled, users can register themselves. If disabled, only administrators can create new users."
                    label="Registration Allowed" />
                <x-forms.checkbox instantSave id="do_not_track"
                    helper="If enabled, Coolify will not track any data. This is useful if you are concerned about privacy."
                    label="Do Not Track" />
                <h4 class="pt-4">DNS Settings</h4>
                <x-forms.checkbox instantSave id="is_dns_validation_enabled"
                    helper="If you set a custom domain, Coolify will validate the domain in your DNS provider."
                    label="DNS Validation" />
                <x-forms.input id="custom_dns_servers" label="Custom DNS Servers"
                    helper="DNS servers to validate domains against. A comma separated list of DNS servers."
                    placeholder="1.1.1.1,8.8.8.8" />
                <h4 class="pt-4">API Settings</h4>
                <x-forms.checkbox instantSave id="is_api_enabled" label="API Access"
                    helper="If enabled, the API will be enabled. If disabled, the API will be disabled." />
                <x-forms.input id="allowed_ips" label="Allowed IPs for API Access"
                    helper="Allowed IP addresses or subnets for API access.<br>Supports single IPs (192.168.1.100) and CIDR notation (192.168.1.0/24).<br>Use comma to separate multiple entries.<br>Use 0.0.0.0 or leave empty to allow from anywhere."
                    placeholder="192.168.1.100,10.0.0.0/8,203.0.113.0/24" />
                <h4 class="pt-4">Confirmation Settings</h4>
                <div class="md:w-96 pb-1">
                    <x-forms.checkbox instantSave id="is_sponsorship_popup_enabled" label="Show Sponsorship Popup"
                        helper="When enabled, sponsorship popups will be shown monthly to users. When disabled, the sponsorship popup will be permanently hidden for all users." />
                </div>
            </div>
            <div class="flex flex-col gap-1">
                @if ($disable_two_step_confirmation)
                    <div class="md:w-96 pb-4" wire:key="two-step-confirmation-enabled">
                        <x-forms.checkbox instantSave id="disable_two_step_confirmation"
                            label="Disable Two Step Confirmation"
                            helper="When disabled, you will not need to confirm actions with a text and user password. This significantly reduces security and may lead to accidental deletions or unwanted changes. Use with extreme caution, especially on production servers." />
                    </div>
                @else
                    <div class="md:w-96 pb-4 flex items-center justify-between gap-2"
                        wire:key="two-step-confirmation-disabled">
                        <label class="flex items-center gap-2">
                            Disable Two Step Confirmation
                            <x-helper
                                helper="When disabled, you will not need to confirm actions with a text and user password. This significantly reduces security and may lead to accidental deletions or unwanted changes. Use with extreme caution, especially on production servers.">
                            </x-helper>
                        </label>
                        <x-modal-confirmation title="Disable Two Step Confirmation?" buttonTitle="Disable" isErrorButton
                            submitAction="toggleTwoStepConfirmation" :actions="[
                                'Two Step confirmation will be disabled globally.',
                                'Disabling two step confirmation reduces security (as anyone can easily delete anything).',
                                'The risk of accidental actions will increase.',
                            ]"
                            confirmationText="DISABLE TWO STEP CONFIRMATION"
                            confirmationLabel="Please type the confirmation text to disable two step confirmation."
                            shortConfirmationLabel="Confirmation text" />
                    </div>
                    <div class="w-full px-4 py-2 mb-4 text-white rounded-xs border-l-4 border-red-500 bg-error">
                        <p class="font-bold">Warning!</p>
                        <p>Disabling two step confirmation reduces security (as anyone can easily delete anything) and
                            increases
                            the risk of accidental actions. This is not recommended for production servers.</p>
                    </div>
                @endif
            </div>
        </form>
    </div>
</div>
