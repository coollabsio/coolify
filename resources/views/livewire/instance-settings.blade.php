<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex flex-col gap-2 xl:flex-row">
            <div class="flex flex-col w-96">
                <x-input name="settings.fqdn" label="FQDN" />
                <x-input name="settings.wildcard_domain" label="Wildcard Domain" />
            </div>
            <div class="flex flex-col w-96">
                <x-input type="number" name="settings.public_port_min" label="Public Port Min" />
                <x-input type="number" name="settings.public_port_max" label="Public Port Max" />
            </div>
        </div>
        <button class="flex mx-auto mt-4" type="submit">
            Submit
        </button>
    </form>
    <div class="flex flex-col pt-4 text-right w-52">
        <x-input instantSave type="checkbox" name="do_not_track" label="Do Not Track" />
        <x-input instantSave type="checkbox" name="is_auto_update_enabled" label="Auto Update?" />
        <x-input instantSave type="checkbox" name="is_registration_enabled" label="Registration Enabled?" />
        <x-input instantSave type="checkbox" name="is_https_forced" label="Force https?" />
    </div>
</div>
