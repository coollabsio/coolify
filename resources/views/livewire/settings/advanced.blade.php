<div>
    <x-slot:title>
        {{ __('settings.advanced_title') }}
        </x-slot>
        <x-settings.navbar />
        <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }"
            class="flex flex-col h-full gap-8 sm:flex-row">
            <x-settings.sidebar activeMenu="advanced" />
            <form wire:submit='submit' class="flex flex-col w-full">
                <div class="flex items-center gap-2">
                    <h2>{{ __('settings.advanced') }}</h2>
                    <x-forms.button type="submit">
                        {{ __('button.save') }}
                    </x-forms.button>
                </div>
                <div class="pb-4">{{ __('settings.advanced_desc') }}</div>

                <div class="flex flex-col gap-1">
                    <div class="md:w-96">
                        <x-forms.checkbox instantSave id="is_registration_enabled"
                            helper="{{ __('settings.registration_allowed_helper') }}"
                            label="{{ __('settings.registration_allowed') }}" />
                    </div>
                    <div class="md:w-96">
                        <x-forms.checkbox instantSave id="do_not_track"
                            helper="{{ __('settings.do_not_track_helper') }}"
                            label="{{ __('settings.do_not_track') }}" />
                    </div>
                    <h4 class="pt-4">{{ __('settings.dns_settings') }}</h4>
                    <div class="md:w-96">
                        <x-forms.checkbox instantSave id="is_dns_validation_enabled"
                            helper="{{ __('settings.dns_validation_helper') }}"
                            label="{{ __('settings.dns_validation') }}" />
                    </div>

                    <x-forms.input id="custom_dns_servers" label="{{ __('settings.custom_dns_servers') }}"
                        helper="{{ __('settings.custom_dns_servers_helper') }}"
                        placeholder="1.1.1.1,8.8.8.8" />
                    <h4 class="pt-4">{{ __('settings.api_settings') }}</h4>
                    <div class="md:w-96">
                        <x-forms.checkbox instantSave id="is_api_enabled" label="{{ __('settings.api_access') }}"
                            helper="{{ __('settings.api_access_helper') }}" />
                    </div>
                    <x-forms.input id="allowed_ips" label="{{ __('settings.allowed_ips') }}"
                        helper="{{ __('settings.allowed_ips_helper') }}"
                        placeholder="192.168.1.100,10.0.0.0/8,203.0.113.0/24" />
                    @if (empty($allowed_ips) || in_array('0.0.0.0', array_map('trim', explode(',', $allowed_ips ?? ''))))
                        <x-callout type="warning" title="{{ __('warning.title') }}" class="mt-2">
                            {{ __('settings.allowed_ips_warning') }}
                        </x-callout>
                    @endif
                    <h4 class="pt-4">{{ __('settings.ui_settings') }}</h4>
                    <div class="md:w-96">
                        <x-forms.checkbox instantSave id="is_wire_navigate_enabled" label="{{ __('settings.spa_navigation') }}"
                            helper="{{ __('settings.spa_navigation_helper') }}" />
                    </div>
                    <h4 class="pt-4">{{ __('settings.confirmation_settings') }}</h4>
                    <div class="md:w-96">
                        <x-forms.checkbox instantSave id="is_sponsorship_popup_enabled" label="{{ __('settings.sponsorship_popup') }}"
                            helper="{{ __('settings.sponsorship_popup_helper') }}" />
                    </div>
                </div>
                <div class="flex flex-col gap-1">
                    @if ($disable_two_step_confirmation)
                        <div class="pb-4 md:w-96" wire:key="two-step-confirmation-enabled">
                            <x-forms.checkbox instantSave id="disable_two_step_confirmation"
                                label="{{ __('settings.disable_two_step') }}"
                                helper="{{ __('settings.disable_two_step_helper') }}" />
                        </div>
                    @else
                                    <div class="pb-4 flex items-center justify-between gap-2 md:w-96"
                                        wire:key="two-step-confirmation-disabled">
                                        <label class="flex items-center gap-2">
                                            {{ __('settings.disable_two_step') }}
                                            <x-helper
                                                helper="{{ __('settings.disable_two_step_helper') }}">
                                            </x-helper>
                                        </label>
                                        <x-modal-confirmation title="{{ __('settings.disable_two_step_confirm_title') }}" buttonTitle="{{ __('button.disable') }}" isErrorButton
                                            submitAction="toggleTwoStepConfirmation" :actions="[
                            __('settings.two_step_warning_1'),
                            __('settings.two_step_warning_2'),
                            __('settings.two_step_warning_3'),
                        ]"
                                            confirmationText="{{ __('settings.disable_two_step_confirm_text') }}"
                                            confirmationLabel="{{ __('settings.disable_two_step_confirm_label') }}"
                                            shortConfirmationLabel="{{ __('settings.confirmation_text') }}" />
                                    </div>
                                    <x-callout type="danger" title="{{ __('warning.title') }}" class="mb-4">
                                        {{ __('settings.disable_two_step_callout') }}
                                    </x-callout>
                    @endif
                </div>
            </form>
        </div>
</div>