<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Advanced | Coolify
    </x-slot>
    <x-server.navbar :server="$server" />
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }" class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="advanced" />
        <form wire:submit='submit' class="w-full">
            <div>
                <div class="flex items-center gap-2">
                    <h2>Advanced</h2>
                    <x-forms.button type="submit">Save</x-forms.button>
                </div>
                <div class="mt-3 mb-4">Advanced configuration for your server.</div>
            </div>

            <h3>Disk Usage</h3>
            <div class="flex flex-col gap-6">
                <div class="flex flex-col">
                    <div class="flex flex-wrap gap-2 sm:flex-nowrap pt-4">
                        <x-forms.input placeholder="0 23 * * *" id="serverDiskUsageCheckFrequency"
                            label="Disk usage check frequency" required
                            helper="Cron expression for disk usage check frequency.<br>You can use every_minute, hourly, daily, weekly, monthly, yearly.<br><br>Default is every night at 11:00 PM." />
                        <x-forms.input id="serverDiskUsageNotificationThreshold"
                            label="Server disk usage notification threshold (%)" required
                            helper="If the server disk usage exceeds this threshold, Coolify will send a notification to the team members." />
                    </div>
                </div>

                <div class="flex flex-col">
                    <h3>Builds</h3>
                    <div>Customize the build process.</div>
                    <div class="flex flex-wrap gap-2 sm:flex-nowrap pt-4">
                        <x-forms.input id="concurrentBuilds" label="Number of concurrent builds" required
                            helper="You can specify the number of simultaneous build processes/deployments that should run concurrently." />
                        <x-forms.input id="dynamicTimeout" label="Deployment timeout (seconds)" required
                            helper="You can define the maximum duration for a deployment to run before timing it out." />
                    </div>
                </div>
            </div>
            
            <div class="flex flex-col gap-4 pt-8">
                <h3>CA SSL Certificate</h3>
                <div class="flex gap-2">
                    <x-modal-confirmation 
                        title="Confirm changing of CA Certificate?" 
                        buttonTitle="Save Certificate"
                        submitAction="saveCaCertificate" 
                        :actions="[
                            'This will overwrite the existing CA certificate at /data/coolify/ssl/coolify-ca.crt with your custom CA certificate.', 
                            'This will regenerate all SSL certificates for databases on this server and it will sign them with your custom CA.',
                            'You must manually redeploy all your databases on this server so that they use the new SSL certificates singned with your new CA certificate.',
                            'Because of caching, you probably also need to redeploy all your resources on this server that are using this CA certificate.'
                        ]"
                        confirmationText="/data/coolify/ssl/coolify-ca.crt"
                        shortConfirmationLabel="CA Certificate Path"
                        step3ButtonText="Save Certificate">
                    </x-modal-confirmation>
                    <x-modal-confirmation 
                        title="Confirm Regenerate Certificate?" 
                        buttonTitle="Regenerate Certificate" 
                        submitAction="regenerateCaCertificate" 
                        :actions="[
                            'This will generate a new CA certificate at /data/coolify/ssl/coolify-ca.crt and replace the existing one.', 
                            'This will regenerate all SSL certificates for databases on this server and it will sign them with the new CA certificate.',
                            'You must manually redeploy all your databases on this server so that they use the new SSL certificates singned with the new CA certificate.',
                            'Because of caching, you probably also need to redeploy all your resources on this server that are using this CA certificate.'
                        ]"
                        confirmationText="/data/coolify/ssl/coolify-ca.crt"
                        shortConfirmationLabel="CA Certificate Path"
                        step3ButtonText="Regenerate Certificate">
                    </x-modal-confirmation>
                </div>
                <div class="space-y-4">
                    <div class="text-sm">
                        <p class="font-medium mb-2">Recommended Configuration:</p>
                        <ul class="list-disc pl-5 space-y-1">
                            <li>Mount this CA certificate of Coolify into all containers that need to connect to one of your databases over SSL. You can see and copy the bind mount below.</li>
                            <li>Read more when and why this is needed <a class="underline" href="https://coolify.io/docs/databases/ssl" target="_blank">here</a>.</li>
                        </ul>
                    </div>
                    <div class="relative">
                        <x-forms.copy-button 
                            text="- /data/coolify/ssl/coolify-ca.crt:/etc/ssl/certs/coolify-ca.crt:ro" 
                        />
                    </div>
                </div>
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <div class="flex items-center gap-2">
                            <span class="text-sm">CA Certificate</span>
                            @if($certificateValidUntil)
                                <span class="text-sm">(Valid until: 
                                    @if(now()->gt($certificateValidUntil))
                                        <span class="text-red-500">{{ $certificateValidUntil->format('d.m.Y H:i:s') }} - Expired)</span>
                                    @elseif(now()->addDays(30)->gt($certificateValidUntil))
                                        <span class="text-red-500">{{ $certificateValidUntil->format('d.m.Y H:i:s') }} - Expiring soon)</span>
                                    @else
                                        <span>{{ $certificateValidUntil->format('d.m.Y H:i:s') }})</span>
                                    @endif
                                </span>
                            @endif
                        </div>
                        <x-forms.button 
                            wire:click="toggleCertificate" 
                            type="button" 
                            class="!py-1 !px-2 text-sm">
                            {{ $showCertificate ? 'Hide' : 'Show' }}
                        </x-forms.button>
                    </div>
                    @if($showCertificate)
                        <textarea 
                            class="w-full h-[370px] input"
                            wire:model="certificateContent"
                            placeholder="Paste or edit CA certificate content here..."></textarea>
                    @else
                        <div class="w-full h-[370px] input">
                            <div class="h-full flex flex-col items-center justify-center text-gray-300">
                                <div class="mb-2">
                                    ━━━━━━━━ CERTIFICATE CONTENT ━━━━━━━━
                                </div>
                                <div class="text-sm">
                                    Click "Show" to view or edit
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </form>
    </div>
</div>
