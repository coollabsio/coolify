<div>
    <form wire:submit='submit'>
        <div class="flex items-center gap-2 pb-4">
            @if ($application->human_name)
                <h2>{{ Str::headline($application->human_name) }}</h2>
            @else
                <h2>{{ Str::headline($application->name) }}</h2>
            @endif
            <x-forms.button canGate="update" :canResource="$application" type="submit">Save</x-forms.button>
            @can('update', $application)
                <x-modal-confirmation wire:click="convertToDatabase" title="Convert to Database"
                    buttonTitle="Convert to Database" submitAction="convertToDatabase" :actions="['The selected resource will be converted to a service database.']"
                    confirmationText="{{ Str::headline($application->name) }}"
                    confirmationLabel="Please confirm the execution of the actions by entering the Service Application Name below"
                    shortConfirmationLabel="Service Application Name" />
            @endcan
            @can('delete', $application)
                <x-modal-confirmation title="Confirm Service Application Deletion?" buttonTitle="Delete" isErrorButton
                    submitAction="delete" :actions="['The selected service application container will be stopped and permanently deleted.']" confirmationText="{{ Str::headline($application->name) }}"
                    confirmationLabel="Please confirm the execution of the actions by entering the Service Application Name below"
                    shortConfirmationLabel="Service Application Name" />
            @endcan
        </div>
        <div class="flex flex-col gap-2">
            <div class="flex gap-2">
                <x-forms.input canGate="update" :canResource="$application" label="Name" id="application.human_name"
                    placeholder="Human readable name"></x-forms.input>
                <x-forms.input canGate="update" :canResource="$application" label="Description"
                    id="application.description"></x-forms.input>
            </div>
            <div class="flex gap-2">
                @if (!$application->serviceType()?->contains(str($application->image)->before(':')))
                    @if ($application->required_fqdn)
                        <x-forms.input canGate="update" :canResource="$application" required placeholder="https://app.coolify.io"
                            label="Domains" id="application.fqdn"
                            helper="You can specify one domain with path or more with comma. You can specify a port to bind the domain to.<br><br><span class='text-helper'>Example</span><br>- http://app.coolify.io,https://cloud.coolify.io/dashboard<br>- http://app.coolify.io/api/v3<br>- http://app.coolify.io:3000 -> app.coolify.io will point to port 3000 inside the container. "></x-forms.input>
                    @else
                        <x-forms.input canGate="update" :canResource="$application" placeholder="https://app.coolify.io"
                            label="Domains" id="application.fqdn"
                            helper="You can specify one domain with path or more with comma. You can specify a port to bind the domain to.<br><br><span class='text-helper'>Example</span><br>- http://app.coolify.io,https://cloud.coolify.io/dashboard<br>- http://app.coolify.io/api/v3<br>- http://app.coolify.io:3000 -> app.coolify.io will point to port 3000 inside the container. "></x-forms.input>
                    @endif
                @endif
                <x-forms.input canGate="update" :canResource="$application"
                    helper="You can change the image you would like to deploy.<br><br><span class='dark:text-warning'>WARNING. You could corrupt your data. Only do it if you know what you are doing.</span>"
                    label="Image" id="application.image"></x-forms.input>
            </div>
        </div>
        <h3 class="py-2 pt-4">Advanced</h3>
        <div class="w-96 flex flex-col gap-1">
            @if (str($application->image)->contains('pocketbase'))
                <x-forms.checkbox canGate="update" :canResource="$application" instantSave id="application.is_gzip_enabled"
                    label="Enable Gzip Compression"
                    helper="Pocketbase does not need gzip compression, otherwise SSE will not work." disabled />
            @else
                <x-forms.checkbox canGate="update" :canResource="$application" instantSave id="application.is_gzip_enabled"
                    label="Enable Gzip Compression"
                    helper="You can disable gzip compression if you want. Some services are compressing data by default. In this case, you do not need this." />
            @endif
            <x-forms.checkbox canGate="update" :canResource="$application" instantSave id="application.is_stripprefix_enabled"
                label="Strip Prefixes"
                helper="Strip Prefix is used to remove prefixes from paths. Like /api/ to /api." />
            <x-forms.checkbox canGate="update" :canResource="$application" instantSave label="Exclude from service status"
                helper="If you do not need to monitor this resource, enable. Useful if this service is optional."
                id="application.exclude_from_status"></x-forms.checkbox>
            <x-forms.checkbox canGate="update" :canResource="$application"
                helper="Drain logs to your configured log drain endpoint in your Server settings."
                instantSave="instantSaveAdvanced" id="application.is_log_drain_enabled" label="Drain Logs" />
        </div>
    </form>
    
    <x-domain-conflict-modal 
        :conflicts="$domainConflicts" 
        :showModal="$showDomainConflictModal" 
        confirmAction="confirmDomainUsage">
        <x-slot:consequences>
            <ul class="mt-2 ml-4 list-disc">
                <li>Only one service will be accessible at this domain</li>
                <li>The routing behavior will be unpredictable</li>
                <li>You may experience service disruptions</li>
                <li>SSL certificates might not work correctly</li>
            </ul>
        </x-slot:consequences>
    </x-domain-conflict-modal>
</div>
