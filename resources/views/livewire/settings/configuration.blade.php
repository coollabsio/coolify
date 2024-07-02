<div>
    <form wire:submit='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2>Configuration</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
        </div>
        <div>General configuration for your Coolify instance.</div>

        <div class="flex flex-col gap-2 pt-4">
            <div class="flex flex-wrap items-end gap-2">
                <x-forms.input id="settings.fqdn" label="Instance's Domain" placeholder="https://coolify.io" />
                <x-forms.input id="settings.instance_name" label="Instance's Name" placeholder="Coolify" />
                <x-forms.input id="settings.custom_dns_servers" label="DNS Servers"
                    helper="DNS servers for validation FQDNs againts. A comma separated list of DNS servers."
                    placeholder="1.1.1.1,8.8.8.8" />
                <div class="md:w-96">
                    <x-forms.checkbox instantSave id="is_dns_validation_enabled" label="Validate DNS settings?" />
                </div>
            </div>

            {{-- <div class="flex gap-2 ">
                <x-forms.input type="number" id="settings.public_port_min" label="Public Port Min" />
                <x-forms.input type="number" id="settings.public_port_max" label="Public Port Max" />
            </div> --}}
        </div>
        <h2 class="pt-6">API</h2>

        <div class="md:w-96">
            <x-forms.checkbox instantSave id="is_api_enabled" label="Enabled" />
        </div>
        <x-forms.input id="settings.allowed_ips" label="Allowed IPs"
            helper="Allowed IP lists for the API. A comma separated list of IPs. Empty means you allow from everywhere."
            placeholder="1.1.1.1,8.8.8.8" />
    </form>

    <h2 class="pt-6">Advanced</h2>
    <div class="text-right md:w-96">
        @if (!is_null(env('AUTOUPDATE', null)))
            <x-forms.checkbox instantSave helper="AUTOUPDATE is set in .env file, you need to modify it there." disabled
                id="is_auto_update_enabled" label="Auto Update Coolify" />
        @else
            <x-forms.checkbox instantSave id="is_auto_update_enabled" label="Auto Update Coolify" />
        @endif
        <x-forms.checkbox instantSave id="is_registration_enabled" label="Registration Allowed" />
        <x-forms.checkbox instantSave id="do_not_track" label="Do Not Track" />
    </div>
</div>
