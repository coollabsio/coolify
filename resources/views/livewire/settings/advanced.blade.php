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
                    <div class="md:w-96">
                        <x-forms.checkbox instantSave id="is_registration_enabled"
                            helper="Allow users to self-register. If disabled, only administrators can create accounts."
                            label="Registration Allowed" />
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
                        helper="Custom DNS servers for domain validation. Comma-separated list (e.g., 1.1.1.1,8.8.8.8). Leave empty to use system defaults."
                        placeholder="1.1.1.1,8.8.8.8" />
                    <h4 class="pt-4">API Settings</h4>
                    <div class="md:w-96">
                        <x-forms.checkbox instantSave id="is_api_enabled" label="API Access"
                            helper="If enabled, authenticated requests to Coolify's REST API will be allowed. Configure API tokens in Security > API Tokens." />
                    </div>
                    <x-forms.input id="allowed_ips" label="Allowed IPs for API Access"
                        helper="Allowed IP addresses or subnets for API access.<br>Supports single IPs (192.168.1.100) and CIDR notation (192.168.1.0/24).<br>Use comma to separate multiple entries.<br>Use 0.0.0.0 or leave empty to allow from anywhere."
                        placeholder="192.168.1.100,10.0.0.0/8,203.0.113.0/24" />
                    @if (empty($allowed_ips) || in_array('0.0.0.0', array_map('trim', explode(',', $allowed_ips ?? ''))))
                        <x-callout type="warning" title="Warning" class="mt-2">
                            Using 0.0.0.0 (or empty) allows API access from anywhere. This is not recommended for production
                            environments!
                        </x-callout>
                    @endif
                    <h4 class="pt-4">UI Settings</h4>
                    <div class="md:w-96">
                        <x-forms.checkbox instantSave id="is_wire_navigate_enabled" label="SPA Navigation"
                            helper="Enable single-page application (SPA) style navigation with prefetching on hover. When enabled, page transitions are smoother without full page reloads and pages are prefetched when hovering over links. Disable if you experience navigation issues." />
                    </div>
                    <h4 class="pt-4">Confirmation Settings</h4>
                    <div class="md:w-96">
                        <x-forms.checkbox instantSave id="is_sponsorship_popup_enabled" label="Show Sponsorship Popup"
                            helper="Show monthly sponsorship reminders to support Coolify development. Disable to hide these messages permanently." />
                    </div>
                </div>
                <div class="flex flex-col gap-1">
                    @if ($disable_two_step_confirmation)
                        <div class="pb-4 md:w-96" wire:key="two-step-confirmation-enabled">
                            <x-forms.checkbox instantSave id="disable_two_step_confirmation"
                                label="Disable Two Step Confirmation"
                                helper="When disabled, you will not need to confirm actions with a text and user password. This significantly reduces security and may lead to accidental deletions or unwanted changes. Use with extreme caution, especially on production servers." />
                        </div>
                    @else
                                    <div class="pb-4 flex items-center justify-between gap-2 md:w-96"
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
                                    <x-callout type="danger" title="Warning!" class="mb-4">
                                        Disabling two step confirmation reduces security (as anyone can easily delete anything) and
                                        increases the risk of accidental actions. This is not recommended for production servers.
                                    </x-callout>
                    @endif
                </div>
            </form>
        </div>
</div>