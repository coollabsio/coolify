<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Host Key | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="security" activeSubMenu="host-key" />

        <div class="application-settings-form flex w-full flex-col gap-6">
            @if (count($pendingFingerprints) > 0)
                <x-callout type="danger" title="The SSH host key of this server changed">
                    <p>The server sent a host key that does not match the key that Coolify trusts.
                        @if ($mode === 'enforce')
                            Coolify does not connect to the server until you trust the new key or reset it.
                        @else
                            Coolify still connects, because the host key check is in warn mode.
                        @endif
                    </p>
                    <p class="mt-2">This is expected after you reinstall the server or move its address to another
                        machine. If you did not do that, another machine can answer at the address of this server.
                        Do not trust the key before you compared it with the key on the server.</p>
                    <p class="mt-2 font-medium">New key:</p>
                    @foreach ($pendingFingerprints as $fingerprint)
                        <code class="block break-all">{{ $fingerprint }}</code>
                    @endforeach
                </x-callout>
            @endif

            <x-application.settings-section id="server-host-key-section" title="SSH host key"
                helper="Coolify saves the host key of the server on the first connection and refuses or reports a different key later.">
                <div class="flex flex-col gap-4 text-sm">
                    @if ($mode === 'off')
                        <x-callout type="warning" title="The host key check is off">
                            @if ($server->settings->is_ssh_host_key_check_disabled)
                                The host key check is disabled for this server.
                            @else
                                The host key check is disabled for this instance (SSH_HOST_KEY_CHECKING=off).
                            @endif
                            Coolify cannot detect another machine that answers at the address of this server.
                        </x-callout>
                    @endif

                    <div>
                        <p class="font-medium text-neutral-950 dark:text-fg">Trusted key</p>
                        @forelse ($fingerprints as $fingerprint)
                            <code class="block break-all">{{ $fingerprint }}</code>
                        @empty
                            <p class="text-neutral-500 dark:text-fg-dim">No key yet. Coolify saves the key on the next
                                connection.</p>
                        @endforelse
                        @if (count($fingerprints) > 0)
                            <p class="mt-1 text-xs text-neutral-500 dark:text-fg-dim">
                                Source: {{ $server->settings->ssh_host_key_source }}
                                @if ($server->settings->ssh_host_key_trusted_at)
                                    &middot; Trusted at {{ $server->settings->ssh_host_key_trusted_at->toDateTimeString() }} UTC
                                @endif
                            </p>
                        @endif
                    </div>

                    <div>
                        <p class="font-medium text-neutral-950 dark:text-fg">Compare it on the server</p>
                        <p class="text-neutral-500 dark:text-fg-dim">Run this command on the server and make sure that
                            one of the results matches the key above:</p>
                        <code class="block break-all">for key in /etc/ssh/ssh_host_*_key.pub; do ssh-keygen -lf "$key"; done</code>
                    </div>

                    <p class="text-xs text-neutral-500 dark:text-fg-dim">Check mode of this instance:
                        <span class="font-medium">{{ $mode }}</span>.
                        warn: Coolify connects and reports a changed key. enforce: Coolify refuses a changed key.</p>

                    @can('update', $server)
                        <div class="flex flex-wrap gap-2"
                            wire:key="host-key-actions-{{ $server->settings->ssh_host_key_pending ? 'pending' : 'none' }}-{{ $server->settings->is_ssh_host_key_check_disabled ? 'off' : 'on' }}">
                            @if (count($pendingFingerprints) > 0)
                                <x-modal-confirmation title="Trust the new host key?" temporaryDisableTwoStepConfirmation
                                    buttonTitle="Trust new key" submitAction="trustPendingKey" :actions="[
                                        'Coolify trusts the new host key and stops trusting the old key.',
                                        'Only do this after you compared the new key with the key on the server.',
                                    ]" confirmationText="{{ $server->name }}"
                                    shortConfirmationLabel="Server Name" step3ButtonText="Trust New Key"
                                    isHighlightedButton />
                            @endif
                            <x-modal-confirmation title="Reset the host key?" temporaryDisableTwoStepConfirmation
                                buttonTitle="Reset" submitAction="resetHostKey" :actions="[
                                    'Coolify removes the trusted key and the new key.',
                                    'Coolify trusts the key that the server sends on the next connection.',
                                ]" confirmationText="{{ $server->name }}" shortConfirmationLabel="Server Name"
                                step3ButtonText="Reset Host Key" isErrorButton />
                            <x-modal-confirmation
                                title="{{ $server->settings->is_ssh_host_key_check_disabled ? 'Enable the host key check?' : 'Disable the host key check?' }}"
                                temporaryDisableTwoStepConfirmation
                                buttonTitle="{{ $server->settings->is_ssh_host_key_check_disabled ? 'Enable host key check' : 'Disable host key check' }}"
                                submitAction="toggleHostKeyCheck" :actions="$server->settings->is_ssh_host_key_check_disabled
                                    ? ['Coolify checks the host key of this server again.']
                                    : [
                                        'Coolify does not check the host key of this server.',
                                        'Use this only for a server that changes its host key often, for example a load balancer address or a server that you rebuild often.',
                                    ]" confirmationText="{{ $server->name }}"
                                shortConfirmationLabel="Server Name" step3ButtonText="Confirm"
                                :isErrorButton="!$server->settings->is_ssh_host_key_check_disabled" />
                        </div>
                    @endcan
                </div>
            </x-application.settings-section>
        </div>
    </div>
</div>
