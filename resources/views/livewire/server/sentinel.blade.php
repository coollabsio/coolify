<div class="application-settings-form flex w-full flex-col gap-6">
    <form wire:submit.prevent="submit" class="contents">
        @if ($isSentinelEnabled)
            <x-unsaved-bar action="submit" />
        @endif

        <x-application.settings-section id="server-sentinel-overview-section" title="Sentinel"
            helper="Monitor server and container health while collecting historical metrics.">
            <x-slot:actions>
                <div class="flex items-center gap-2">
                    <x-status-badge
                        :status="!$isSentinelEnabled
                            ? 'Disabled'
                            : ($server->isSentinelLive() ? 'In sync' : 'Out of sync')"
                        :type="!$isSentinelEnabled
                            ? 'neutral'
                            : ($server->isSentinelLive() ? 'success' : 'warning')" />
                    @if (!$isSentinelEnabled)
                        <x-forms.button canGate="update" :canResource="$server" isHighlighted
                            wire:click="toggleSentinel">
                            Enable Sentinel
                        </x-forms.button>
                    @else
                        <x-forms.button wire:click="restartSentinel" canGate="update"
                            :canResource="$server">
                            <x-reicon name="refresh" class="size-3.5" />
                            {{ $server->isSentinelLive() ? 'Restart' : 'Sync' }}
                        </x-forms.button>
                        <x-forms.button canGate="update" :canResource="$server"
                            wire:click="toggleSentinel">
                            Disable
                        </x-forms.button>
                    @endif
                </div>
            </x-slot:actions>

            @if ($isSentinelEnabled && !$server->isSentinelLive())
                <x-callout type="warning" title="Sentinel is out of sync">
                    Sync Sentinel to apply its current configuration and restore health reporting.
                </x-callout>
            @elseif ($isSentinelEnabled)
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
            @else
                <x-empty size="sm" title="Sentinel is disabled"
                    description="Enable Sentinel to collect metrics and monitor server and container health.">
                    <x-slot:icon>
                        <x-reicon name="dashboard" class="size-8" />
                    </x-slot:icon>
                </x-empty>
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

            <x-application.settings-section id="server-sentinel-metrics-section" title="Metrics collection"
                helper="Control collection frequency, retention, and the push interval.">
                <div class="grid gap-4 lg:grid-cols-3">
                    <x-forms.input canGate="update" :canResource="$server" type="number" min="1"
                        id="sentinelMetricsRefreshRateSeconds" label="Collection rate" required
                        helper="Seconds between metric samples." />
                    <x-forms.input canGate="update" :canResource="$server" type="number" min="1"
                        id="sentinelMetricsHistoryDays" label="History retention" required
                        helper="Days of CPU and memory history to retain." />
                    <x-forms.input canGate="update" :canResource="$server" type="number" min="10"
                        id="sentinelPushIntervalSeconds" label="Push interval" required
                        helper="Seconds between health reports sent to Coolify." />
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
                            saveCustomImage() {
                                localStorage.setItem('sentinel_custom_docker_image_{{ $server->uuid }}', this.customImage);
                                $wire.set('sentinelCustomDockerImage', this.customImage);
                            }
                        }" x-init="$wire.set('sentinelCustomDockerImage', customImage)">
                            <x-forms.input canGate="update" :canResource="$server" x-model="customImage"
                                @input.debounce.500ms="saveCustomImage()"
                                placeholder="sentinel:latest" label="Custom Docker image"
                                helper="Leave empty to use the default Sentinel image." />
                        </div>
                    </div>
                </x-application.settings-section>
            @endif
        @endif
    </form>
</div>
