<div>
    <x-slot:title>
        Settings | Coolify
    </x-slot>
    <x-settings.navbar />
    <form wire:submit='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2>Configuration</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
        </div>
        <div>General configuration for your Coolify instance.</div>

        <div class="flex flex-col gap-2">
            <div class="flex flex-wrap items-end gap-2">
                <h4 class="pt-6">Instance Settings</h4>
                <x-forms.input id="settings.fqdn" label="Instance's Domain" placeholder="https://coolify.io" />
                <x-forms.input id="settings.instance_name" label="Instance's Name" placeholder="Coolify" />
                <div class="w-full" x-data="{ 
                    open: false, 
                    search: '{{ $settings->instance_timezone }}', 
                    timezones: @js($timezones),
                    placeholder: 'Select Instance Timezone'
                }">
                    <label for="settings.instance_timezone" class="dark:text-white flex items-center">
                        Instance Timezone
                        <x-helper class="ml-2" helper="Timezone for the Coolify instance (this does NOT change your server's timezone in /etc/timezone, /etc/localtime, etc.). This is used for the update check and automatic update frequency." />
                    </label>
                    <div class="relative">
                        <input
                            x-model="search"
                            @focus="open = true"
                            @click.away="open = false"
                            @input="open = true"
                            class="w-full input"
                            :placeholder="placeholder"
                            wire:model.debounce.300ms="settings.instance_timezone"
                        >
                        <div x-show="open" class="absolute z-50 w-full mt-1 bg-white dark:bg-coolgray-100 border border-gray-300 dark:border-white rounded-md shadow-lg max-h-60 overflow-auto">
                            <template x-for="timezone in timezones.filter(tz => tz.toLowerCase().includes(search.toLowerCase()))" :key="timezone">
                                <div
                                    @click="search = timezone; open = false; $wire.set('settings.instance_timezone', timezone)"
                                    class="px-4 py-2 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-800 dark:text-gray-200"
                                    x-text="timezone"
                                ></div>
                            </template>
                        </div>
                    </div>
                </div>
                <h4 class="w-full pt-6">DNS Validation</h4>
                <div class="md:w-96">
                    <x-forms.checkbox instantSave id="is_dns_validation_enabled" label="Enabled" />
                </div>
                <x-forms.input id="settings.custom_dns_servers" label="DNS Servers"
                    helper="DNS servers for validation FQDNs againts. A comma separated list of DNS servers."
                    placeholder="1.1.1.1,8.8.8.8" />
            </div>

            {{-- <div class="flex gap-2 ">
                <x-forms.input type="number" id="settings.public_port_min" label="Public Port Min" />
                <x-forms.input type="number" id="settings.public_port_max" label="Public Port Max" />
            </div> --}}

        </div>
        <h4 class="pt-6">API</h4>
        <div class="md:w-96">
            <x-forms.checkbox instantSave id="is_api_enabled" label="Enabled" />
        </div>
        <x-forms.input id="settings.allowed_ips" label="Allowed IPs"
            helper="Allowed IP lists for the API. A comma separated list of IPs. Empty means you allow from everywhere."
            placeholder="1.1.1.1,8.8.8.8" />

        <h4 class="pt-6">Advanced</h4>
        <div class="text-right md:w-96">
            <x-forms.checkbox instantSave id="is_registration_enabled" label="Registration Allowed" />
            <x-forms.checkbox instantSave id="do_not_track" label="Do Not Track" />
        </div>
        <h5 class="pt-4 font-bold text-white">Update</h5>
        <div class="text-right md:w-96">
            @if (!is_null(env('AUTOUPDATE', null)))
                <div class="text-right md:w-96">
                    <x-forms.checkbox instantSave helper="AUTOUPDATE is set in .env file, you need to modify it there."
                        disabled id="is_auto_update_enabled" label="Enabled" />
                </div>
            @else
                <x-forms.checkbox instantSave id="is_auto_update_enabled" label="Auto Update Enabled" />
            @endif
        </div>
        <div class="flex flex-col gap-2">
            <div class="flex items-end gap-2">
                <x-forms.input required id="update_check_frequency" label="Update Check Frequency"
                    placeholder="0 * * * *"
                    helper="Cron expression for update check frequency (check for new Coolify versions and pull new Service Templates from CDN).<br>You can use every_minute, hourly, daily, weekly, monthly, yearly.<br><br>Default is every hour." />
                <x-forms.button wire:click='checkManually'>Check Manually</x-forms.button>
            </div>

            @if (is_null(env('AUTOUPDATE', null)) && $is_auto_update_enabled)
                <x-forms.input required id="auto_update_frequency" label="Auto Update Frequency" placeholder="0 0 * * *"
                    helper="Cron expression for auto update frequency (automatically update coolify).<br>You can use every_minute, hourly, daily, weekly, monthly, yearly.<br><br>Default is every day at 00:00" />
            @endif
        </div>
    </form>

</div>