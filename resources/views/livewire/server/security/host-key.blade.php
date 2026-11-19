<div class="application-settings-form flex w-full flex-col gap-6">
    @if (count($pendingFingerprints) > 0)
        <x-callout type="danger" title="The SSH host key of this server changed">
            @foreach ($pendingFingerprints as $fingerprint)
                <code class="block break-all">{{ $fingerprint }}</code>
            @endforeach
            <p class="mt-2">{{ $mode === 'enforce' ? 'Coolify does not connect until you trust or reset the key.' : 'Coolify still connects (warn mode).' }}
                Expected after a reinstall. Otherwise, compare the key on the server before you trust it.</p>
        </x-callout>
    @endif

    <x-application.settings-section id="server-host-key-section" title="SSH host key"
        helper="Coolify trusts the first host key of the server. Later, a different key is reported (warn mode, the default) or refused (enforce mode). The setting below sets enforce mode for this server only.<br><br>An instance admin sets the mode for all servers with <code>SSH_HOST_KEY_CHECKING=enforce</code> (or <code>warn</code>, <code>off</code>) in <code>/data/coolify/source/.env</code>, then restarts Coolify.">
        <x-slot:badges>
            @if (count($pendingFingerprints) > 0)
                <x-status-badge status="Key changed" type="error" />
            @elseif ($mode === 'off')
                <x-status-badge status="Check off" type="warning" />
            @elseif (count($fingerprints) > 0)
                <x-status-badge :status="'Trusted, ' . $mode . ' mode'" type="success" />
            @else
                <x-status-badge status="No key yet" type="neutral" />
            @endif
        </x-slot:badges>
        <x-slot:actions>
            @can('update', $server)
                <div class="flex flex-wrap items-center gap-2"
                    wire:key="host-key-actions-{{ $server->settings->ssh_host_key_pending ? hash('sha256', $server->settings->ssh_host_key_pending) : 'none' }}-{{ $server->settings->is_ssh_host_key_check_disabled ? 'off' : 'on' }}">
                    @if (count($pendingFingerprints) > 0)
                        <x-modal-confirmation title="Trust the new host key?" temporaryDisableTwoStepConfirmation
                            buttonTitle="Trust new key"
                            submitAction="trustPendingKey('{{ hash('sha256', $server->settings->ssh_host_key_pending) }}')"
                            :actions="[
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
                        buttonTitle="{{ $server->settings->is_ssh_host_key_check_disabled ? 'Enable check' : 'Disable check' }}"
                        submitAction="setHostKeyCheckDisabled({{ $server->settings->is_ssh_host_key_check_disabled ? 0 : 1 }})"
                        :actions="$server->settings->is_ssh_host_key_check_disabled
                            ? ['Coolify checks the host key of this server again.']
                            : [
                                'Coolify does not check the host key of this server.',
                                'Use this only for a server that changes its host key often, for example a load balancer address or a server that you rebuild often.',
                            ]" confirmationText="{{ $server->name }}"
                        shortConfirmationLabel="Server Name" step3ButtonText="Confirm"
                        :isErrorButton="!$server->settings->is_ssh_host_key_check_disabled" />
                </div>
            @endcan
        </x-slot:actions>

        <div class="flex flex-col gap-3 text-sm">
            @if ($mode === 'off')
                <x-callout type="warning" title="The host key check is off">
                    {{ $server->settings->is_ssh_host_key_check_disabled ? 'Disabled for this server.' : 'Disabled for this instance (SSH_HOST_KEY_CHECKING=off).' }}
                </x-callout>
            @endif

            <div class="flex min-w-0 items-start gap-3">
                <div
                    class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
                    <x-reicon name="keys" class="size-4" />
                </div>
                <div class="min-w-0">
                    @forelse ($fingerprints as $fingerprint)
                        <code class="block break-all text-xs">{{ $fingerprint }}</code>
                    @empty
                        <p class="font-medium text-neutral-950 dark:text-fg">No key yet</p>
                    @endforelse
                    <p class="mt-1 text-xs text-neutral-500 dark:text-fg-dim">
                        @if (count($fingerprints) > 0)
                            {{ match ($server->settings->ssh_host_key_source) {
                                'tofu' => 'Saved on the first connection',
                                'upgrade' => 'Saved on the first connection after the Coolify upgrade',
                                'transfer' => 'Moved with the server transfer',
                                'manual' => 'Saved by a user',
                                default => 'Saved',
                            } }}
                            @if ($server->settings->ssh_host_key_trusted_at)
                                &middot; Trusted {{ $server->settings->ssh_host_key_trusted_at->toDateTimeString() }} UTC
                            @endif
                            &middot;
                        @else
                            Saved on the next connection &middot;
                        @endif
                        Mode: {{ $mode }}{{ $mode === 'enforce' && $instanceMode !== 'enforce' ? ' (set for this server)' : '' }}
                    </p>
                </div>
            </div>

            {{-- The choice only changes something when the instance warns; an instance "enforce" or "off" decides alone. --}}
            @if ($instanceMode !== 'enforce' && $instanceMode !== 'off' && ! $server->settings->is_ssh_host_key_check_disabled)
                <div class="max-w-sm">
                    <x-forms.listbox id="checkMode" label="When the host key changes" onChange="setHostKeyCheckMode"
                        :onChangeArgs="[]" canGate="update" :canResource="$server"
                        helper="Warn: Coolify connects and reports the new key. Enforce: Coolify refuses to connect until you trust or reset the new key, so deployments, backups, and checks on this server stop. A reinstall of the server also changes the key."
                        :options="[
                            ['value' => 'warn', 'label' => 'Warn and connect'],
                            ['value' => 'enforce', 'label' => 'Refuse to connect (enforce)'],
                        ]" />
                </div>
            @endif
        </div>
    </x-application.settings-section>
</div>
