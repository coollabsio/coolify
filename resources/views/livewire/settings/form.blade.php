<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex flex-col gap-2 xl:flex-row">
            <div class="flex flex-col w-96">
                <x-inputs.input id="settings.fqdn" label="FQDN" />
                <x-inputs.input id="settings.wildcard_domain" label="Wildcard Domain" />
            </div>
            <div class="flex flex-col w-96">
                <x-inputs.input type="number" id="settings.public_port_min" label="Public Port Min" />
                <x-inputs.input type="number" id="settings.public_port_max" label="Public Port Max" />
            </div>
        </div>
        <x-inputs.button class="w-16 mt-4" type="submit">
            Submit
        </x-inputs.button>
    </form>
    <div class="flex flex-col pt-4 text-right w-52">
        <x-inputs.input instantSave type="checkbox" id="do_not_track" label="Do Not Track" />
        <x-inputs.input instantSave type="checkbox" id="is_auto_update_enabled" label="Auto Update?" />
        <x-inputs.input instantSave type="checkbox" id="is_registration_enabled" label="Registration Enabled?" />
        <x-inputs.input instantSave type="checkbox" id="is_https_forced" label="Force https?" />
    </div>
</div>
