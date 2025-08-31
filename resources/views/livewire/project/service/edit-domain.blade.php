<div class="w-full">
    <form wire:submit.prevent='submit' class="flex flex-col w-full gap-2">
        <div class="pb-2">Note: If a service has a defined port, do not delete it. <br>If you want to use your custom
            domain, you can add it with a port.</div>
        <x-forms.input canGate="update" :canResource="$application" placeholder="https://app.coolify.io" label="Domains"
            id="application.fqdn"
            helper="You can specify one domain with path or more with comma. You can specify a port to bind the domain to.<br><br><span class='text-helper'>Example</span><br>- http://app.coolify.io,https://cloud.coolify.io/dashboard<br>- http://app.coolify.io/api/v3<br>- http://app.coolify.io:3000 -> app.coolify.io will point to port 3000 inside the container. "></x-forms.input>
        <x-forms.button canGate="update" :canResource="$application" type="submit">Save</x-forms.button>
    </form>

    <x-domain-conflict-modal :conflicts="$domainConflicts" :showModal="$showDomainConflictModal" confirmAction="confirmDomainUsage">
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
