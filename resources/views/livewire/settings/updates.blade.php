<div>
    <x-slot:title>
        {{ __('settings.auto_update_title') }}
    </x-slot>
    <x-settings.navbar />
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }" class="flex flex-col h-full gap-8 sm:flex-row">
        <x-settings.sidebar activeMenu="updates" />
        <form wire:submit='submit' class="flex flex-col w-full">
            <div class="flex items-center gap-2">
                <h2>{{ __('settings.updates') }}</h2>
                <x-forms.button type="submit">
                    {{ __('button.save') }}
                </x-forms.button>
            </div>
            <div class="pb-4">{{ __('settings.updates_desc') }}</div>


            <div class="flex flex-col gap-2">
                <div class="flex items-end gap-2">
                    <x-forms.input required id="update_check_frequency" label="{{ __('settings.update_check_frequency') }}"
                        placeholder="0 * * * *"
                        helper="{{ __('settings.update_check_frequency_helper') }}" />
                    <x-forms.button wire:click='checkManually'>{{ __('settings.check_manually') }}</x-forms.button>
                </div>

                <h4 class="pt-4">{{ __('settings.auto_update') }}</h4>

                <div class="text-right md:w-64">
                    @if (!is_null(config('constants.coolify.autoupdate', null)))
                        <div class="text-right">
                            <x-forms.checkbox instantSave
                                helper="{{ __('settings.autoupdate_env_helper') }}" disabled
                                checked="{{ config('constants.coolify.autoupdate') }}" label="{{ __('settings.enabled') }}" />
                        </div>
                    @else
                        <x-forms.checkbox instantSave id="is_auto_update_enabled" label="{{ __('settings.enabled') }}" />
                    @endif
                </div>
                @if (is_null(config('constants.coolify.autoupdate', null)) && $is_auto_update_enabled)
                    <x-forms.input required id="auto_update_frequency" label="{{ __('settings.frequency_cron') }}"
                        placeholder="0 0 * * *"
                        helper="{{ __('settings.auto_update_frequency_helper') }}" />
                @else
                    <x-forms.input required label="{{ __('settings.frequency_cron') }}" disabled placeholder="disabled"
                        helper="{{ __('settings.auto_update_frequency_helper') }}" />
                @endif
            </div>

        </form>
    </div>
</div>
