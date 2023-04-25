<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex flex-col gap-2 xl:flex-row">
            <div class="flex flex-col w-96">
                <x-form-input id="settings.fqdn" label="FQDN" />
                <x-form-input id="settings.wildcard_domain" label="Wildcard Domain" />
            </div>
            <div class="flex flex-col w-96">
                <x-form-input type="number" id="settings.public_port_min" label="Public Port Min" />
                <x-form-input type="number" id="settings.public_port_max" label="Public Port Max" />
            </div>
        </div>
        <button class="w-16 mt-4" type="submit">
            Submit
        </button>
    </form>
    <div class="flex flex-col pt-4 text-right w-52">
        <x-form-input instantSave type="checkbox" id="do_not_track" label="Do Not Track" />
        <x-form-input instantSave type="checkbox" id="is_auto_update_enabled" label="Auto Update?" />
        <x-form-input instantSave type="checkbox" id="is_registration_enabled" label="Registration Enabled?" />
        <x-form-input instantSave type="checkbox" id="is_https_forced" label="Force https?" />
    </div>
</div>
