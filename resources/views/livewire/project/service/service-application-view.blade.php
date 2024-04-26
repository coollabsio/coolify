<div>
    <form wire:submit='submit'>
        <div class="flex items-center gap-2 pb-4">
            @if ($application->human_name)
                <h2>{{ Str::headline($application->human_name) }}</h2>
            @else
                <h2>{{ Str::headline($application->name) }}</h2>
            @endif
            <x-forms.button type="submit">Save</x-forms.button>
            <x-modal-confirmation isErrorButton>
                <x-slot:button-title>
                    Delete
                </x-slot:button-title>
                This will delete this service application. It is not reversible.<br>Please think again.
            </x-modal-confirmation>
        </div>
        <div class="flex flex-col gap-2">
            <div class="flex gap-2">
                <x-forms.input label="Name" id="application.human_name"
                    placeholder="Human readable name"></x-forms.input>
                <x-forms.input label="Description" id="application.description"></x-forms.input>
            </div>
            <div class="flex gap-2">
                @if (!$application->serviceType()?->contains(str($application->image)->before(':')))
                    @if ($application->required_fqdn)
                        <x-forms.input required placeholder="https://app.coolify.io" label="Domains"
                            id="application.fqdn"
                            helper="You can specify one domain with path or more with comma. You can specify a port to bind the domain to.<br><br><span class='text-helper'>Example</span><br>- http://app.coolify.io,https://cloud.coolify.io/dashboard<br>- http://app.coolify.io/api/v3<br>- http://app.coolify.io:3000 -> app.coolify.io will point to port 3000 inside the container. "></x-forms.input>
                    @else
                        <x-forms.input placeholder="https://app.coolify.io" label="Domains" id="application.fqdn"
                            helper="You can specify one domain with path or more with comma. You can specify a port to bind the domain to.<br><br><span class='text-helper'>Example</span><br>- http://app.coolify.io,https://cloud.coolify.io/dashboard<br>- http://app.coolify.io/api/v3<br>- http://app.coolify.io:3000 -> app.coolify.io will point to port 3000 inside the container. "></x-forms.input>
                    @endif
                @endif
                <x-forms.input required
                    helper="You can change the image you would like to deploy.<br><br><span class='dark:text-warning'>WARNING. You could corrupt your data. Only do it if you know what you are doing.</span>"
                    label="Image" id="application.image"></x-forms.input>
            </div>
        </div>
        <h3 class="pt-2">Advanced</h3>
        <div class="w-96">
            <x-forms.checkbox instantSave id="application.is_gzip_enabled" label="Enable gzip compression"
                helper="You can disable gzip compression if you want. Some services are compressing data by default. In this case, you do not need this." />
            <x-forms.checkbox instantSave id="application.is_stripprefix_enabled" label="Strip Prefixes"
                helper="Strip Prefix is used to remove prefixes from paths. Like /api/ to /api." />
            <x-forms.checkbox instantSave label="Exclude from service status"
                helper="If you do not need to monitor this resource, enable. Useful if this service is optional."
                id="application.exclude_from_status"></x-forms.checkbox>
            <x-forms.checkbox helper="Drain logs to your configured log drain endpoint in your Server settings."
                instantSave="instantSaveAdvanced" id="application.is_log_drain_enabled" label="Drain Logs" />
        </div>
    </form>
</div>
