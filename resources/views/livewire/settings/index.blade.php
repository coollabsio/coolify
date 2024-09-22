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
            <h4 class="pt-6">Instance Settings</h4>
            <div class="flex flex-wrap items-end gap-2">
                <div class="flex gap-2 md:flex-row flex-col w-full">
                    <x-forms.input id="settings.fqdn" label="Instance's Domain"
                        helper="Enter the full domain name (FQDN) of the instance, including 'https://' if you want to secure the dashboard with HTTPS. Setting this will make the dashboard accessible via this domain, secured by HTTPS, instead of just the IP address."
                        placeholder="https://coolify.yourdomain.com" />
                    <x-forms.input id="settings.instance_name" label="Instance's Name" placeholder="Coolify" />
                    <div class="w-full" x-data="{
                        open: false,
                        search: '{{ $settings->instance_timezone ?: '' }}',
                        timezones: @js($timezones),
                        placeholder: '{{ $settings->instance_timezone ? 'Search timezone...' : 'Select Server Timezone' }}',
                        init() {
                            this.$watch('search', value => {
                                if (value === '') {
                                    this.open = true;
                                }
                            })
                        }
                    }">
                        <div class="flex items-center mb-1">
                            <label for="settings.instance_timezone">Instance
                                Timezone</label>
                            <x-helper class="ml-2"
                                helper="Timezone for the Coolify instance. This is used for the update check and automatic update frequency." />
                        </div>
                        <div class="relative">
                            <div class="inline-flex items-center relative w-full">
                                <input wire:dirty.class.remove='dark:focus:ring-coolgray-300 dark:ring-coolgray-300'
                                    wire:dirty.class="dark:focus:ring-warning dark:ring-warning" x-model="search"
                                    @focus="open = true" @click.away="open = false" @input="open = true"
                                    class="w-full input " :placeholder="placeholder"
                                    wire:model.debounce.300ms="settings.instance_timezone">
                                <svg class="absolute right-0 w-4 h-4 mr-2" xmlns="http://www.w3.org/2000/svg"
                                    fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                                    @click="open = true">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
                                </svg>
                            </div>
                            <div x-show="open"
                                class="absolute z-50 w-full  mt-1 bg-white dark:bg-coolgray-100 border dark:border-coolgray-200 rounded-md shadow-lg max-h-60 overflow-auto scrollbar overflow-x-hidden">
                                <template
                                    x-for="timezone in timezones.filter(tz => tz.toLowerCase().includes(search.toLowerCase()))"
                                    :key="timezone">
                                    <div @click="search = timezone; open = false; $wire.set('settings.instance_timezone', timezone)"
                                        class="px-4 py-2 cursor-pointer hover:bg-gray-100 dark:hover:bg-coolgray-300 text-gray-800 dark:text-gray-200"
                                        x-text="timezone"></div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <h4 class="w-full pt-6">DNS Validation</h4>
                <div class="md:w-96">
                    <x-forms.checkbox instantSave id="is_dns_validation_enabled" label="Enabled" />
                </div>
                <x-forms.input id="settings.custom_dns_servers" label="DNS Servers"
                    helper="DNS servers to validate FQDNs against. A comma separated list of DNS servers."
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
