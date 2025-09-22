<div>
    <x-slot:title>
        Auto Update | Coolify
    </x-slot>
    <x-settings.navbar />
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }" class="flex flex-col h-full gap-8 sm:flex-row">
        <x-settings.sidebar activeMenu="updates" />
        <form wire:submit='submit' class="flex flex-col w-full">
            <div class="flex items-center gap-2">
                <h2>Updates</h2>
                <x-forms.button type="submit">
                    Save
                </x-forms.button>
            </div>
            <div class="pb-4">Your instance's update settings.</div>


            <div class="flex flex-col gap-2">
                <div class="flex items-end gap-2">
                    <x-forms.input required id="update_check_frequency" label="Update Check Frequency"
                        placeholder="0 * * * *"
                        helper="Frequency (cron expression) to check for new Coolify versions and pull new Service Templates from CDN.<br>You can use every_minute, hourly, daily, weekly, monthly, yearly.<br><br>Default is every hour." />
                    <x-forms.button wire:click='checkManually'>Check Manually</x-forms.button>
                </div>

                <h4 class="pt-4">Auto Update</h4>

                <div class="text-right md:w-64">
                    @if (!is_null(config('constants.coolify.autoupdate', null)))
                        <div class="text-right">
                            <x-forms.checkbox instantSave
                                helper="AUTOUPDATE is set in .env file, you need to modify it there." disabled
                                checked="{{ config('constants.coolify.autoupdate') }}" label="Enabled" />
                        </div>
                    @else
                        <x-forms.checkbox instantSave id="is_auto_update_enabled" label="Enabled" />
                    @endif
                </div>
                @if (is_null(config('constants.coolify.autoupdate', null)) && $is_auto_update_enabled)
                    <x-forms.input required id="auto_update_frequency" label="Frequency (cron expression)"
                        placeholder="0 0 * * *"
                        helper="Frequency (cron expression) (automatically update coolify).<br>You can use every_minute, hourly, daily, weekly, monthly, yearly.<br><br>Default is every day at 00:00" />
                @else
                    <x-forms.input required label="Frequency (cron expression)" disabled placeholder="disabled"
                        helper="Frequency (cron expression) (automatically update coolify).<br>You can use every_minute, hourly, daily, weekly, monthly, yearly.<br><br>Default is every day at 00:00" />
                @endif
            </div>

        </form>
    </div>
</div>
