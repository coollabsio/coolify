<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex gap-2">
            <h1 class="pb-2">Settings</h1>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
        </div>
        <div class="pb-4 text-sm">Instance wide settings for Coolify.</div>
        <div class="flex flex-col gap-2">
            <div class="flex gap-2">
                <x-forms.input id="settings.fqdn" label="Coolify's Domain" />
                <x-forms.input id="settings.wildcard_domain" label="Wildcard Domain"
                    helper="Wildcard domain for your applications. If you set this, you will get a random generated domain for your new applications.<br><span class='font-bold text-white'>Example</span>In case you set:<span class='text-helper'>https://example.com</span>your applications will get: <span class='text-helper'>https://randomId.example.com</span>" />
                <x-forms.input id="settings.default_redirect_404" label="Default Redirect 404"
                    helper="All urls that has no service available will be redirected to this domain.<span class='text-helper'>You can set to your main marketing page or your social media link.</span>" />
            </div>
            <div class="flex gap-2 ">
                <x-forms.input type="number" id="settings.public_port_min" label="Public Port Min" />
                <x-forms.input type="number" id="settings.public_port_max" label="Public Port Max" />
            </div>
        </div>
    </form>
    <h3>Advanced</h3>
    <div class="flex flex-col text-right w-52">
        <x-forms.checkbox instantSave id="is_auto_update_enabled" label="Auto Update Coolify" />
        <x-forms.checkbox instantSave id="is_registration_enabled" label="Registration Allowed" />
        {{-- <x-forms.checkbox instantSave id="is_https_forced" label="Force https?" /> --}}
        <x-forms.checkbox instantSave id="do_not_track" label="Do Not Track" />
    </div>

</div>
