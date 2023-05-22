<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex items-center gap-2 border-b-2 border-solid border-coolgray-200">
            <h1>Settings</h1>
            <x-inputs.button type="submit">
                Save
            </x-inputs.button>
        </div>
        <div class="flex flex-col gap-2">
            <div class="flex flex-col gap-2 xl:flex-row">
                <x-inputs.input id="settings.fqdn" label="Coolify's Domain" />
                <x-inputs.input id="settings.wildcard_domain" label="Wildcard Domain"
                    helper="Wildcard domain for your applications. If you set this, you will get a random generated domain for your new applications.<br><br><span class='inline-block font-bold text-warning'>Example</span>https://example.com<br>Your applications will get https://randomthing.example.com" />
            </div>
            <div class="flex flex-col gap-2 xl:flex-row">
                <x-inputs.input type="number" id="settings.public_port_min" label="Public Port Min" />
                <x-inputs.input type="number" id="settings.public_port_max" label="Public Port Max" />
            </div>
        </div>
    </form>

    <h3>Advanced</h3>
    <div class="flex flex-col pt-4 text-right w-52">
        <x-inputs.checkbox instantSave id="is_auto_update_enabled" label="Auto Update Coolify" />
        <x-inputs.checkbox instantSave id="is_registration_enabled" label="Registration Allowed" />
        {{-- <x-inputs.checkbox instantSave id="is_https_forced" label="Force https?" /> --}}
        <x-inputs.checkbox instantSave id="do_not_track" label="Do Not Track" />
    </div>
</div>
