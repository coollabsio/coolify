<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > CA Certificate | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-8 grid w-full max-w-[1180px] min-w-0 gap-8 xl:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
        <x-server.sidebar :server="$server" activeMenu="ca-certificate" />

        <div class="application-settings-form flex w-full flex-col gap-6">
            <x-application.settings-section id="server-ca-overview-section" title="CA certificate"
                helper="Manage the certificate authority used to sign database certificates on this server.">
                <x-slot:actions>
                    @if ($certificateValidUntil)
                        <x-status-badge
                            :status="now()->gt($certificateValidUntil)
                                ? 'Expired'
                                : (now()->addDays(30)->gt($certificateValidUntil) ? 'Expiring soon' : 'Valid')"
                            :type="now()->gt($certificateValidUntil) || now()->addDays(30)->gt($certificateValidUntil)
                                ? 'error'
                                : 'success'" />
                    @endif
                </x-slot:actions>

                <x-callout type="info" title="Using this certificate">
                    Mount the CA certificate into containers that connect to databases over SSL. Re-deploy affected
                    databases and resources after replacing or regenerating it.
                    <a class="font-medium underline" href="https://coolify.io/docs/databases/ssl" target="_blank">
                        Read the SSL guide.
                    </a>
                </x-callout>

                <div class="mt-4">
                    <p class="mb-1.5 text-xs font-medium text-neutral-500 dark:text-fg-dim">Read-only bind mount</p>
                    <x-forms.copy-button
                        text="- /data/coolify/ssl/coolify-ca.crt:/etc/ssl/certs/coolify-ca.crt:ro" />
                </div>
            </x-application.settings-section>

            <x-application.settings-section id="server-ca-content-section" title="Certificate content"
                helper="Review or replace the PEM certificate stored on this server.">
                <x-slot:actions>
                    <div class="flex items-center gap-2">
                        @can('view', $server)
                            <x-forms.button wire:click="toggleCertificate" type="button">
                                {{ $showCertificate ? 'Hide certificate' : 'Show certificate' }}
                            </x-forms.button>
                        @endcan
                        @can('update', $server)
                            <x-modal-confirmation title="Confirm changing of CA Certificate?"
                                buttonTitle="Save certificate" submitAction="saveCaCertificate" :actions="[
                                    'This overwrites /data/coolify/ssl/coolify-ca.crt with your custom certificate.',
                                    'Database certificates on this server will be regenerated and signed with the custom CA.',
                                    'You must redeploy affected databases and resources.',
                                ]" confirmationText="/data/coolify/ssl/coolify-ca.crt"
                                shortConfirmationLabel="CA Certificate Path"
                                step3ButtonText="Save Certificate" />
                            <x-modal-confirmation title="Confirm Regenerate Certificate?"
                                buttonTitle="Regenerate" submitAction="regenerateCaCertificate" :actions="[
                                    'This replaces the current CA certificate with a newly generated certificate.',
                                    'Database certificates on this server will be regenerated and signed with the new CA.',
                                    'You must redeploy affected databases and resources.',
                                ]" confirmationText="/data/coolify/ssl/coolify-ca.crt"
                                shortConfirmationLabel="CA Certificate Path"
                                step3ButtonText="Regenerate Certificate" />
                        @endcan
                    </div>
                </x-slot:actions>

                @if ($showCertificate)
                    <x-forms.textarea canGate="update" :canResource="$server" id="certificateContent"
                        rows="15" label="PEM certificate"
                        placeholder="Paste or edit CA certificate content here…" />
                @else
                    <div
                        class="flex min-h-72 flex-col items-center justify-center rounded-lg bg-neutral-100/70 px-6 text-center ring-1 ring-neutral-200 dark:bg-black/20 dark:ring-white/[0.08]">
                        <x-reicon name="keys" class="size-8 text-neutral-300 dark:text-fg-faint" />
                        <p class="mt-3 text-sm font-medium text-neutral-950 dark:text-fg">Certificate hidden</p>
                        <p class="mt-1 text-xs text-neutral-500 dark:text-fg-dim">
                            Show the certificate to review or edit its contents.
                        </p>
                    </div>
                @endif
            </x-application.settings-section>
        </div>
    </div>
</div>
