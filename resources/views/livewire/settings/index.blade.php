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
                    <x-forms.input id="fqdn" label="Instance's Domain"
                        helper="Enter the full domain name (FQDN) of the instance, including 'https://' if you want to secure the dashboard with HTTPS. Setting this will make the dashboard accessible via this domain, secured by HTTPS, instead of just the IP address."
                        placeholder="https://coolify.yourdomain.com" />
                    <x-forms.input id="instance_name" label="Instance's Name" placeholder="Coolify" />
                    <div class="w-full" x-data="{
                        open: false,
                        search: '{{ $settings->instance_timezone ?: '' }}',
                        timezones: @js($this->timezones),
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
                            <label for="instance_timezone">Instance
                                Timezone</label>
                            <x-helper class="ml-2"
                                helper="Timezone for the Coolify instance. This is used for the update check and automatic update frequency." />
                        </div>
                        <div class="relative">
                            <div class="inline-flex relative items-center w-full">
                                <input autocomplete="off"
                                    wire:dirty.class.remove='dark:focus:ring-coolgray-300 dark:ring-coolgray-300'
                                    wire:dirty.class="dark:focus:ring-warning dark:ring-warning" x-model="search"
                                    @focus="open = true" @click.away="open = false" @input="open = true"
                                    class="w-full input" :placeholder="placeholder" wire:model="instance_timezone">
                                <svg class="absolute right-0 mr-2 w-4 h-4" xmlns="http://www.w3.org/2000/svg"
                                    fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                                    @click="open = true">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
                                </svg>
                            </div>
                            <div x-show="open"
                                class="overflow-auto overflow-x-hidden absolute z-50 mt-1 w-full max-h-60 bg-white rounded-md border shadow-lg dark:bg-coolgray-100 dark:border-coolgray-200 scrollbar">
                                <template
                                    x-for="timezone in timezones.filter(tz => tz.toLowerCase().includes(search.toLowerCase()))"
                                    :key="timezone">
                                    <div @click="search = timezone; open = false; $wire.set('instance_timezone', timezone); $wire.submit()"
                                        class="px-4 py-2 text-gray-800 cursor-pointer hover:bg-gray-100 dark:hover:bg-coolgray-300 dark:text-gray-200"
                                        x-text="timezone"></div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="flex gap-2 md:flex-row flex-col w-full">
                    <x-forms.input id="public_ipv4" type="password" label="Instance's IPv4"
                        helper="Enter the IPv4 address of the instance.<br><br>It is useful if you have several IPv4 addresses and Coolify could not detect the correct one."
                        placeholder="1.2.3.4" autocomplete="new-password" />
                    <x-forms.input id="public_ipv6" type="password" label="Instance's IPv6"
                        helper="Enter the IPv6 address of the instance.<br><br>It is useful if you have several IPv6 addresses and Coolify could not detect the correct one."
                        placeholder="2001:db8::1" autocomplete="new-password" />
                </div>
                <h4 class="w-full pt-6">DNS Validation</h4>
                <div class="md:w-96">
                    <x-forms.checkbox instantSave id="is_dns_validation_enabled" label="Enabled" />
                </div>
                <x-forms.input id="custom_dns_servers" label="DNS Servers"
                    helper="DNS servers to validate FQDNs against. A comma separated list of DNS servers."
                    placeholder="1.1.1.1,8.8.8.8" />
            </div>

            {{-- <div class="flex gap-2 ">
                <x-forms.input type="number" id="public_port_min" label="Public Port Min" />
                <x-forms.input type="number" id="public_port_max" label="Public Port Max" />
            </div> --}}

        </div>
        <h4 class="pt-6">API</h4>
        <div class="pb-4">For API documentation, please visit <a class="dark:text-warning underline"
                href="/docs/api">/docs/api</a></div>
        <div class="md:w-96 pb-2">
            <x-forms.checkbox instantSave id="is_api_enabled" label="Enabled" />
        </div>
        <x-forms.input id="allowed_ips" label="Allowed IPs"
            helper="Allowed IP lists for the API. A comma separated list of IPs. Empty means you allow from everywhere."
            placeholder="1.1.1.1,8.8.8.8" />
        <h4 class="pt-6">Update</h4>
        <div class="text-right md:w-96">
            @if (!is_null(config('constants.coolify.autoupdate', null)))
                <div class="text-right md:w-96">
                    <x-forms.checkbox instantSave helper="AUTOUPDATE is set in .env file, you need to modify it there."
                        disabled checked="{{ config('constants.coolify.autoupdate') }}" label="Auto Update Enabled" />
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

            @if (is_null(config('constants.coolify.autoupdate', null)) && $is_auto_update_enabled)
                <x-forms.input required id="auto_update_frequency" label="Auto Update Frequency" placeholder="0 0 * * *"
                    helper="Cron expression for auto update frequency (automatically update coolify).<br>You can use every_minute, hourly, daily, weekly, monthly, yearly.<br><br>Default is every day at 00:00" />
            @endif
        </div>

        <h4 class="pt-6">Advanced</h4>
        <div class="text-right md:w-96">
            <x-forms.checkbox instantSave id="is_registration_enabled" label="Registration Allowed" />
            <x-forms.checkbox instantSave id="do_not_track" label="Do Not Track" />
        </div>

        <h4 class="py-4">Confirmation Settings</h4>

        @if ($disable_two_step_confirmation)
            <div class="md:w-96 pb-4">
                <x-forms.checkbox instantSave id="disable_two_step_confirmation" label="Disable Two Step Confirmation"
                    helper="When disabled, you will not need to confirm actions with a text and user password. This significantly reduces security and may lead to accidental deletions or unwanted changes. Use with extreme caution, especially on production servers." />
            </div>
        @else
            <div class="md:w-96 pb-4">
                <x-modal-confirmation title="Disable Two Step Confirmation?"
                    buttonTitle="Disable Two Step Confirmation" isErrorButton submitAction="toggleTwoStepConfirmation"
                    :actions="[
                        'Two Step confimation will be disabled globally.',
                        'Disabling two step confirmation reduces security (as anyone can easily delete anything).',
                        'The risk of accidental actions will increase.',
                    ]" confirmationText="DISABLE TWO STEP CONFIRMATION"
                    confirmationLabel="Please type the confirmation text to disable two step confirmation."
                    shortConfirmationLabel="Confirmation text" step3ButtonText="Disable Two Step Confirmation" />
            </div>
            <div class="w-full px-4 py-2 mb-4 text-white rounded-sm border-l-4 border-red-500 bg-error">
                <p class="font-bold">Warning!</p>
                <p>Disabling two step confirmation reduces security (as anyone can easily delete anything) and
                    increases
                    the risk of accidental actions. This is not recommended for production servers.</p>
            </div>
        @endif
    </form>
</div>
