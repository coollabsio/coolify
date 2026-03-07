<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Sentinel | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="sentinel" />
        <div class="w-full">
            <form wire:submit.prevent='submit'>
                <div class="flex gap-2 items-center pb-2">
                    <h2>Sentinel</h2>
                    <x-helper helper="Sentinel reports your server's & container's health and collects metrics." />
                    @if ($server->isSentinelEnabled())
                        <div class="flex gap-2 items-center">
                            @if ($server->isSentinelLive())
                                <x-status.running status="In sync" noLoading title="{{ $sentinelUpdatedAt }}" />
                                <x-forms.button type="submit" canGate="update" :canResource="$server">Save</x-forms.button>
                                <x-forms.button wire:click='restartSentinel' canGate="update" :canResource="$server">Restart</x-forms.button>
                                <x-slide-over fullScreen>
                                    <x-slot:title>Sentinel Logs</x-slot:title>
                                    <x-slot:content>
                                        <livewire:project.shared.get-logs :server="$server"
                                            container="coolify-sentinel" displayName="Sentinel" :collapsible="false"
                                            lazy />
                                    </x-slot:content>
                                    <x-forms.button @click="slideOverOpen=true">Logs</x-forms.button>
                                </x-slide-over>
                            @else
                                <x-status.stopped status="Out of sync" noLoading
                                    title="{{ $sentinelUpdatedAt }}" />
                                <x-forms.button type="submit" canGate="update" :canResource="$server">Save</x-forms.button>
                                <x-forms.button wire:click='restartSentinel' canGate="update" :canResource="$server">Sync</x-forms.button>
                                <x-slide-over fullScreen>
                                    <x-slot:title>Sentinel Logs</x-slot:title>
                                    <x-slot:content>
                                        <livewire:project.shared.get-logs :server="$server"
                                            container="coolify-sentinel" displayName="Sentinel" :collapsible="false"
                                            lazy />
                                    </x-slot:content>
                                    <x-forms.button @click="slideOverOpen=true">Logs</x-forms.button>
                                </x-slide-over>
                            @endif
                        </div>
                    @endif
                </div>
                <div class="flex flex-col gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$server" wire:model.live="isSentinelEnabled"
                            label="Enable Sentinel" />
                        @if ($server->isSentinelEnabled())
                            @if (isDev())
                                <x-forms.checkbox canGate="update" :canResource="$server" id="isSentinelDebugEnabled"
                                    label="Enable Sentinel (with debug)" instantSave />
                            @endif
                            <x-forms.checkbox canGate="update" :canResource="$server" instantSave
                                id="isMetricsEnabled" label="Enable Metrics" />
                        @else
                            @if (isDev())
                                <x-forms.checkbox id="isSentinelDebugEnabled" label="Enable Sentinel (with debug)"
                                    disabled instantSave />
                            @endif
                            <x-forms.checkbox instantSave disabled id="isMetricsEnabled"
                                label="Enable Metrics (enable Sentinel first)" />
                        @endif
                    </div>
                    @if (isDev() && $server->isSentinelEnabled())
                        <div class="pt-4" x-data="{
                            customImage: localStorage.getItem('sentinel_custom_docker_image_{{ $server->uuid }}') || '',
                            saveCustomImage() {
                                localStorage.setItem('sentinel_custom_docker_image_{{ $server->uuid }}', this.customImage);
                                $wire.set('sentinelCustomDockerImage', this.customImage);
                            }
                        }" x-init="$wire.set('sentinelCustomDockerImage', customImage)">
                            <x-forms.input x-model="customImage" @input.debounce.500ms="saveCustomImage()"
                                placeholder="e.g., sentinel:latest or myregistry/sentinel:dev"
                                label="Custom Sentinel Docker Image (Dev Only)"
                                helper="Override the default Sentinel Docker image for testing. Leave empty to use the default." />
                        </div>
                    @endif
                    @if ($server->isSentinelEnabled())
                        <div class="flex flex-wrap gap-2 sm:flex-nowrap items-end">
                            <x-forms.input canGate="update" :canResource="$server" type="password" id="sentinelToken"
                                label="Sentinel token" required helper="Token for Sentinel." />
                            <x-forms.button canGate="update" :canResource="$server"
                                wire:click="regenerateSentinelToken">Regenerate</x-forms.button>
                        </div>

                        <x-forms.input canGate="update" :canResource="$server" id="sentinelCustomUrl" required
                            label="Coolify URL"
                            helper="URL to your Coolify instance. If it is empty that means you do not have a FQDN set for your Coolify instance." />

                        <div class="flex flex-col gap-2">
                            <div class="flex flex-wrap gap-2 sm:flex-nowrap">
                                <x-forms.input canGate="update" :canResource="$server"
                                    id="sentinelMetricsRefreshRateSeconds" label="Metrics rate (seconds)" required
                                    helper="Interval used for gathering metrics. Lower values result in more disk space usage." />
                                <x-forms.input canGate="update" :canResource="$server" id="sentinelMetricsHistoryDays"
                                    label="Metrics history (days)" required
                                    helper="Number of days to retain metrics data for." />
                                <x-forms.input canGate="update" :canResource="$server"
                                    id="sentinelPushIntervalSeconds" label="Push interval (seconds)" required
                                    helper="Interval at which metrics data is sent to the collector." />
                            </div>
                        </div>
                    @endif
                </div>
            </form>
        </div>
    </div>
</div>
