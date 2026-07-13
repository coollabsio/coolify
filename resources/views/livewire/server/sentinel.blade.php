<div>
    <form wire:submit.prevent='submit'>
        <div class="flex gap-2 items-center pb-2">
            <h2>Sentinel</h2>
            <x-helper helper="Sentinel reports your server's & container's health and collects metrics." />
            @if (!$isSentinelEnabled)
                <x-forms.button canGate="update" :canResource="$server" isHighlighted wire:click="toggleSentinel">Enable Sentinel</x-forms.button>
            @else
                <div class="flex gap-2 items-center">
                    <x-forms.button type="submit" canGate="update" :canResource="$server">Save</x-forms.button>
                    <x-forms.button wire:click='restartSentinel' canGate="update" :canResource="$server">
                        {{ $server->isSentinelLive() ? 'Restart' : 'Sync' }}
                    </x-forms.button>
                    <x-forms.button canGate="update" :canResource="$server" wire:click="toggleSentinel">Disable Sentinel</x-forms.button>
                </div>
            @endif
        </div>
        @if ($isSentinelEnabled && !$server->isSentinelLive())
            <x-callout type="warning" title="Out of Sync" class="mt-2">
                Sentinel is not in sync with your server. Click "Sync" to re-sync.
            </x-callout>
        @endif
        <div class="flex flex-col gap-2 pt-2">
            @if ($isSentinelEnabled && isDev())
                <div class="w-full sm:w-96">
                    <x-forms.checkbox canGate="update" :canResource="$server" id="isSentinelDebugEnabled"
                        label="Enable Sentinel (with debug)" instantSave />
                </div>
            @endif
            @if (isDev() && $server->isSentinelEnabled())
                <div class="pt-4" x-data="{
                    customImage: localStorage.getItem('sentinel_custom_docker_image_{{ $server->uuid }}') || '',
                    saveCustomImage() {
                        localStorage.setItem('sentinel_custom_docker_image_{{ $server->uuid }}', this.customImage);
                        $wire.set('sentinelCustomDockerImage', this.customImage);
                    }
                }" x-init="$wire.set('sentinelCustomDockerImage', customImage)">
                    <x-forms.input canGate="update" :canResource="$server" x-model="customImage"
                        @input.debounce.500ms="saveCustomImage()"
                        placeholder="e.g., sentinel:latest or myregistry/sentinel:dev"
                        label="Custom Sentinel Docker Image (Dev Only)"
                        helper="Override the default Sentinel Docker image for testing. Leave empty to use the default." />
                </div>
            @endif
            @if ($server->isSentinelEnabled())
                <div class="flex flex-wrap gap-2 sm:flex-nowrap items-end">
                    <x-forms.input canGate="update" :canResource="$server" id="sentinelCustomUrl" required
                        label="Coolify URL"
                        helper="URL to your Coolify instance. If it is empty that means you do not have a FQDN set for your Coolify instance." />
                    <x-forms.input canGate="update" :canResource="$server" type="password" id="sentinelToken"
                        label="Sentinel token" required helper="Token for Sentinel." />
                    <x-forms.button canGate="update" :canResource="$server"
                        wire:click="regenerateSentinelToken">Regenerate</x-forms.button>
                </div>

                <div class="flex flex-col gap-2">
                    <div class="flex flex-wrap gap-2 sm:flex-nowrap">
                        <x-forms.input canGate="update" :canResource="$server" type="number" min="1"
                            id="sentinelMetricsRefreshRateSeconds" label="Metrics rate (seconds)" required
                            helper="Interval used for gathering metrics. Lower values result in more disk space usage." />
                        <x-forms.input canGate="update" :canResource="$server" type="number" min="1"
                            id="sentinelMetricsHistoryDays"
                            label="Metrics history (days)" required
                            helper="Number of days to retain metrics data for." />
                        <x-forms.input canGate="update" :canResource="$server" type="number" min="10"
                            id="sentinelPushIntervalSeconds" label="Push interval (seconds)" required
                            helper="Interval at which metrics data is sent to the collector." />
                    </div>
                </div>
            @endif
        </div>
    </form>
</div>
