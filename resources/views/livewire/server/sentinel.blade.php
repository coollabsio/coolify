@php
    $sentinelStatusLabel = match ($sentinelStatus) {
        'restarting' => 'Restarting',
        'waiting' => 'Waiting for first report',
        'in_sync' => 'In sync',
        default => 'Out of sync',
    };
    $sentinelStatusType = match ($sentinelStatus) {
        'in_sync' => 'success',
        'out_of_sync' => 'warning',
        default => 'neutral',
    };
@endphp

<div class="application-settings-form flex w-full flex-col gap-6" wire:poll.10s="refreshSentinelStatus">
    <form wire:submit.prevent="submit" class="contents">
        {{-- Scope dirty tracking to savable form fields only. Without wire:target,
             Livewire compares the entire component snapshot — so dev-only x-init
             `$wire.set('sentinelCustomDockerImage', …)` (and similar) briefly
             flashes this bar on every page open. --}}
        <x-unsaved-bar action="submit"
            targets="sentinelCustomUrl,sentinelToken" />

        <x-application.settings-section id="server-sentinel-overview-section" title="Sentinel"
            helper="Monitor server and container health while collecting historical metrics.">
            <x-slot:actions>
                <div class="flex items-center gap-2">
                    <x-status-badge :status="$sentinelStatusLabel" :type="$sentinelStatusType" />
                    <x-forms.button wire:click="restartSentinel" canGate="update"
                        :canResource="$server">
                        <x-reicon name="refresh" class="size-3.5" />
                        {{ $sentinelStatus === 'in_sync' ? 'Restart' : 'Sync' }}
                    </x-forms.button>
                </div>
            </x-slot:actions>

            @if ($sentinelStatus === 'out_of_sync')
                <x-callout type="warning" title="Sentinel is out of sync">
                    <div class="space-y-3">
                        <p>Sentinel has not reported within the expected interval. Check these items before syncing again:</p>
                        <ul class="list-disc space-y-1 pl-4">
                            <li>Confirm that the <code>coolify-sentinel</code> container is running.</li>
                            <li>
                                <a class="font-medium underline underline-offset-2"
                                    href="{{ route('server.sentinel.logs', ['server_uuid' => $server->uuid]) }}"
                                    wire:navigate>Open Sentinel logs</a>
                                and review recent connection or push errors.
                            </li>
                            <li>Confirm that the Coolify URL and Sentinel token match this configuration.</li>
                        </ul>

                        @if ($server->isLocalhost())
                            <p>Sync Sentinel to recreate it on the Coolify Docker network.</p>
                        @else
                            <div class="space-y-2">
                                <p>The remote server needs outbound access to this Coolify URL. Sentinel reporting does not require an inbound listening port.</p>
                                @if (filled($sentinelCustomUrl))
                                    <p>
                                        From the remote server, test
                                        <code class="break-all">curl -fsS {{ escapeshellarg(rtrim($sentinelCustomUrl, '/') . '/api/health') }}</code>.
                                    </p>
                                @else
                                    <p>Set a reachable Coolify URL before syncing Sentinel.</p>
                                @endif
                                <p>Check DNS, TLS certificates, outbound firewall rules, and proxy settings.</p>
                            </div>
                        @endif
                    </div>
                </x-callout>
            @elseif ($sentinelStatus === 'in_sync')
                <p class="text-sm text-neutral-500 dark:text-fg-dim">
                    Sentinel is connected and reporting server health to this Coolify instance.
                </p>
            @elseif ($sentinelStatus === 'restarting')
                <p class="text-sm text-neutral-500 dark:text-fg-dim">Sentinel is restarting.</p>
            @else
                <p class="text-sm text-neutral-500 dark:text-fg-dim">Sentinel started and is waiting for its first authenticated report.</p>
            @endif
        </x-application.settings-section>

        @if ($server->isSentinelEnabled())
            <x-application.settings-section id="server-sentinel-connection-section" title="Connection"
                helper="Configure how Sentinel authenticates with and reports to Coolify.">
                <x-slot:actions>
                    <div class="flex items-center gap-2">
                        @can('manageSentinel', $server)
                            <x-modal-confirmation title="Restore default Sentinel configuration?"
                                buttonTitle="Restore defaults" submitAction="restoreDefaultConfiguration"
                                :actions="[
                                    'Restore the generated Coolify URL and default collection settings.',
                                    'Clear debug logging and the development image override.',
                                    'The Sentinel token and metrics setting will be preserved.',
                                    'Restart Sentinel to apply the restored configuration.',
                                ]" warningMessage="Your custom Sentinel configuration will be replaced with Coolify defaults."
                                :confirmWithText="false" :confirmWithPassword="false"
                                step2ButtonText="Restore defaults" />
                        @endcan
                        <x-forms.button canGate="update" :canResource="$server"
                            wire:click="regenerateSentinelToken">
                            Regenerate token
                        </x-forms.button>
                    </div>
                </x-slot:actions>
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input canGate="update" :canResource="$server" id="sentinelCustomUrl"
                        required label="Coolify URL"
                        helper="Public URL used by Sentinel to reach this Coolify instance." />
                    <x-forms.input canGate="update" :canResource="$server" type="password"
                        id="sentinelToken" label="Sentinel token" required
                        helper="Authentication token used by Sentinel." />
                </div>
            </x-application.settings-section>

            @if (isDev())
                <x-application.settings-section id="server-sentinel-development-section"
                    title="Development overrides"
                    helper="Local testing controls that are unavailable in production.">
                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.listbox id="isSentinelDebugEnabled" label="Debug logging"
                            onChange="instantSave" :options="[
                                ['value' => false, 'label' => 'Standard logging'],
                                ['value' => true, 'label' => 'Enable debug logging'],
                            ]" />
                        <div x-data="{
                            customImage: localStorage.getItem('sentinel_custom_docker_image_{{ $server->uuid }}') || '',
                            async applyCustomImage() {
                                localStorage.setItem('sentinel_custom_docker_image_{{ $server->uuid }}', this.customImage);
                                await $wire.set('sentinelCustomDockerImage', this.customImage || null);
                                await $wire.restartSentinel();
                            }
                        }"
                            @sentinel-defaults-restored.window="localStorage.removeItem('sentinel_custom_docker_image_{{ $server->uuid }}'); customImage = ''"
                            {{-- Only hydrate Livewire when a real override exists. Unconditional
                                 $wire.set('', null→'') on every open marks the component dirty and
                                 flashes the unsaved bar until the round-trip completes. --}}
                            x-init="if (customImage) { $wire.set('sentinelCustomDockerImage', customImage) }">
                            <div class="flex items-end gap-2">
                                <div class="min-w-0 flex-1">
                                    <x-forms.input canGate="update" :canResource="$server" x-model="customImage"
                                        placeholder="sentinel:latest" label="Custom Docker image"
                                        helper="Leave empty to use the default Sentinel image." />
                                </div>
                                <x-forms.button canGate="update" :canResource="$server"
                                    x-on:click="applyCustomImage()">
                                    Apply and restart
                                </x-forms.button>
                            </div>
                        </div>
                    </div>
                </x-application.settings-section>
            @endif
        @endif
    </form>
</div>
