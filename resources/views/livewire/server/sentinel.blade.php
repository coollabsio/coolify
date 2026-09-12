<div class="application-settings-form flex w-full flex-col gap-6">
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
                    <x-status-badge :status="$server->isSentinelLive() ? 'In sync' : 'Out of sync'"
                        :type="$server->isSentinelLive() ? 'success' : 'warning'" />
                    <x-forms.button wire:click="restartSentinel" canGate="update"
                        :canResource="$server">
                        <x-reicon name="refresh" class="size-3.5" />
                        {{ $server->isSentinelLive() ? 'Restart' : 'Sync' }}
                    </x-forms.button>
                </div>
            </x-slot:actions>

            @if (!$server->isSentinelLive())
                <x-callout type="warning" title="Sentinel is out of sync">
                    Sync Sentinel to apply its current configuration and restore health reporting.
                </x-callout>
            @else
                <div class="flex items-start gap-3">
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
                        <x-reicon name="dashboard" class="size-4" />
                    </div>
                    <div>
                        <p class="text-sm font-medium text-neutral-950 dark:text-fg">Health reporting active</p>
                        <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                            Sentinel is connected and reporting server health to this Coolify instance.
                        </p>
                    </div>
                </div>
            @endif
        </x-application.settings-section>

        @if ($server->isSentinelEnabled())
            <x-application.settings-section id="server-sentinel-connection-section" title="Connection"
                helper="Configure how Sentinel authenticates with and reports to Coolify.">
                <x-slot:actions>
                    <x-forms.button canGate="update" :canResource="$server"
                        wire:click="regenerateSentinelToken">
                        Regenerate token
                    </x-forms.button>
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

            @if (isDev() && config('constants.sentinel.host_enabled', false))
                <x-application.settings-section id="server-sentinel-flux-development-section"
                    title="Flux control channel"
                    helper="Experimental direct connection from the host Sentinel to Flux.">
                    <x-slot:actions>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-forms.button wire:click="testFluxConnection" wire:loading.attr="disabled"
                                wire:target="testFluxConnection" canGate="update" :canResource="$server">
                                <span wire:loading.remove wire:target="testFluxConnection">Test connection</span>
                                <span wire:loading wire:target="testFluxConnection">Testing...</span>
                            </x-forms.button>
                            <x-forms.button wire:click="refreshFluxConnection" wire:loading.attr="disabled"
                                wire:target="refreshFluxConnection">
                                <span wire:loading.remove wire:target="refreshFluxConnection">Refresh state</span>
                                <span wire:loading wire:target="refreshFluxConnection">Refreshing...</span>
                            </x-forms.button>
                            <x-forms.button canGate="update" :canResource="$server" wire:click="renewFluxCertificate"
                                wire:loading.attr="disabled" wire:target="renewFluxCertificate">
                                <span wire:loading.remove wire:target="renewFluxCertificate">Renew certificate</span>
                                <span wire:loading wire:target="renewFluxCertificate">Renewing...</span>
                            </x-forms.button>
                            <x-forms.button canGate="update" :canResource="$server" wire:click="repairFluxTrust"
                                wire:loading.attr="disabled" wire:target="repairFluxTrust">
                                <span wire:loading.remove wire:target="repairFluxTrust">Repair trust</span>
                                <span wire:loading wire:target="repairFluxTrust">Repairing...</span>
                            </x-forms.button>
                        </div>
                    </x-slot:actions>
                    @if ($fluxConnection)
                        <div class="grid gap-4 text-sm lg:grid-cols-2">
                            <div><span class="text-neutral-500 dark:text-fg-dim">Status</span><p class="font-medium text-neutral-950 dark:text-fg">{{ data_get($fluxConnection, 'status') === 'reconnecting' ? 'Reconnecting' : 'Connected' }}</p></div>
                            <div><span class="text-neutral-500 dark:text-fg-dim">Transport</span><p class="font-medium text-neutral-950 dark:text-fg">{{ data_get($fluxConnection, 'transport') === 'tls' ? 'TLS' : 'Plaintext' }}</p></div>
                            <div><span class="text-neutral-500 dark:text-fg-dim">Endpoint</span><p class="break-all font-mono text-xs text-neutral-950 dark:text-fg">{{ data_get($fluxConnection, 'endpoint') }}</p></div>
                            <div><span class="text-neutral-500 dark:text-fg-dim">Protocol</span><p class="font-medium text-neutral-950 dark:text-fg">{{ data_get($fluxConnection, 'protocol_version') }}</p></div>
                            <div><span class="text-neutral-500 dark:text-fg-dim">Trust bundle</span><p class="font-medium text-neutral-950 dark:text-fg">Version {{ data_get($fluxConnection, 'trust_bundle_version', 'Unknown') }}</p></div>
                            <div><span class="text-neutral-500 dark:text-fg-dim">Connected at</span><p class="font-medium text-neutral-950 dark:text-fg">{{ data_get($fluxConnection, 'connected_at') }}</p></div>
                            <div><span class="text-neutral-500 dark:text-fg-dim">Last heartbeat</span><p class="font-medium text-neutral-950 dark:text-fg">{{ data_get($fluxConnection, 'last_heartbeat_at', 'Waiting for heartbeat') }}</p></div>
                        </div>
                        @if (data_get($fluxConnection, 'transport') !== 'tls')
                            <x-callout type="warning" title="Unencrypted control channel">
                                This development Flux connection does not use TLS.
                            </x-callout>
                        @endif
                    @else
                        <x-status-badge status="Disconnected" type="warning" />
                    @endif
                </x-application.settings-section>

                <x-application.settings-section id="server-sentinel-host-development-section"
                    title="Host Sentinel"
                    helper="Install the experimental host-native Sentinel service for local v5 development.">
                    <x-slot:actions>
                        <x-forms.button canGate="update" :canResource="$server"
                            wire:click="installHostSentinel" wire:loading.attr="disabled"
                            wire:target="installHostSentinel">
                            Install host Sentinel
                        </x-forms.button>
                    </x-slot:actions>
                    <x-callout type="warning" title="Development feature">
                        This installs Sentinel as a systemd service on the selected development server. The existing
                        Sentinel container continues to own metrics and traffic collection.
                    </x-callout>
                </x-application.settings-section>
            @endif
        @endif
    </form>
</div>
