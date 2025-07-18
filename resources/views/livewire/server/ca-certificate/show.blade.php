<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > CA Certificate | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="ca-certificate" />
        <div class="flex flex-col gap-4">
            <div class="flex items-center gap-2">
                <h2>CA Certificate</h2>
                <div class="flex gap-2">
                    <x-modal-confirmation title="Confirm changing of CA Certificate?" buttonTitle="Save"
                        submitAction="saveCaCertificate" :actions="[
                            'This will overwrite the existing CA certificate at /data/coolify/ssl/coolify-ca.crt with your custom CA certificate.',
                            'This will regenerate all SSL certificates for databases on this server and it will sign them with your custom CA.',
                            'You must manually redeploy all your databases on this server so that they use the new SSL certificates singned with your new CA certificate.',
                            'Because of caching, you probably also need to redeploy all your resources on this server that are using this CA certificate.',
                        ]"
                        confirmationText="/data/coolify/ssl/coolify-ca.crt" shortConfirmationLabel="CA Certificate Path"
                        step3ButtonText="Save Certificate">
                    </x-modal-confirmation>
                    <x-modal-confirmation title="Confirm Regenerate Certificate?" buttonTitle="Regenerate "
                        submitAction="regenerateCaCertificate" :actions="[
                            'This will generate a new CA certificate at /data/coolify/ssl/coolify-ca.crt and replace the existing one.',
                            'This will regenerate all SSL certificates for databases on this server and it will sign them with the new CA certificate.',
                            'You must manually redeploy all your databases on this server so that they use the new SSL certificates singned with the new CA certificate.',
                            'Because of caching, you probably also need to redeploy all your resources on this server that are using this CA certificate.',
                        ]"
                        confirmationText="/data/coolify/ssl/coolify-ca.crt" shortConfirmationLabel="CA Certificate Path"
                        step3ButtonText="Regenerate Certificate">
                    </x-modal-confirmation>
                </div>
            </div>
            <div class="space-y-4">
                <div class="text-sm">
                    <p class="font-medium mb-2">Recommended Configuration:</p>
                    <ul class="list-disc pl-5 space-y-1">
                        <li>Mount this CA certificate of Coolify into all containers that need to connect to one of
                            your databases over SSL. You can see and copy the bind mount below.</li>
                        <li>Read more when and why this is needed <a class="underline dark:text-white"
                                href="https://coolify.io/docs/databases/ssl" target="_blank">here</a>.</li>
                    </ul>
                </div>
                <div class="relative">
                    <x-forms.copy-button text="- /data/coolify/ssl/coolify-ca.crt:/etc/ssl/certs/coolify-ca.crt:ro" />
                </div>
            </div>
            <div>
                <div class="flex items-center justify-between mb-2">
                    <div class="flex items-center gap-2">
                        <span class="text-sm">CA Certificate</span>
                        @if ($certificateValidUntil)
                            <span class="text-sm">(Valid until:
                                @if (now()->gt($certificateValidUntil))
                                    <span class="text-red-500">{{ $certificateValidUntil->format('d.m.Y H:i:s') }} -
                                        Expired)</span>
                                @elseif(now()->addDays(30)->gt($certificateValidUntil))
                                    <span class="text-red-500">{{ $certificateValidUntil->format('d.m.Y H:i:s') }} -
                                        Expiring soon)</span>
                                @else
                                    <span>{{ $certificateValidUntil->format('d.m.Y H:i:s') }})</span>
                                @endif
                            </span>
                        @endif
                    </div>
                    <x-forms.button wire:click="toggleCertificate" type="button" class="py-1! px-2! text-sm">
                        {{ $showCertificate ? 'Hide' : 'Show' }}
                    </x-forms.button>
                </div>
                @if ($showCertificate)
                    <textarea class="w-full h-[370px] input" wire:model="certificateContent"
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
    </div>
</div>
