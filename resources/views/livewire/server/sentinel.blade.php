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
                    <h2>{{ __('server.sentinel') }}</h2>
                    <x-helper helper="{{ __('server.sentinel_description') }}" />
                    @if ($server->isSentinelEnabled())
                        <div class="flex gap-2 items-center">
                            @if ($server->isSentinelLive())
                                <x-status.running status="{{ __('server.in_sync') }}" noLoading title="{{ $sentinelUpdatedAt }}" />
                                <x-forms.button type="submit" canGate="update" :canResource="$server">{{ __('common.save') }}</x-forms.button>
                                <x-forms.button wire:click='restartSentinel' canGate="update" :canResource="$server">{{ __('common.restart') }}</x-forms.button>
                                <x-slide-over fullScreen>
                                    <x-slot:title>{{ __('server.sentinel_logs') }}</x-slot:title>
                                    <x-slot:content>
                                        <livewire:project.shared.get-logs :server="$server"
                                            container="coolify-sentinel" displayName="Sentinel" :collapsible="false"
                                            lazy />
                                    </x-slot:content>
                                    <x-forms.button @click="slideOverOpen=true">{{ __('common.logs') }}</x-forms.button>
                                </x-slide-over>
                            @else
                                <x-status.stopped status="{{ __('server.out_of_sync') }}" noLoading
                                    title="{{ $sentinelUpdatedAt }}" />
                                <x-forms.button type="submit" canGate="update" :canResource="$server">{{ __('common.save') }}</x-forms.button>
                                <x-forms.button wire:click='restartSentinel' canGate="update" :canResource="$server">{{ __('common.sync') }}</x-forms.button>
                                <x-slide-over fullScreen>
                                    <x-slot:title>{{ __('server.sentinel_logs') }}</x-slot:title>
                                    <x-slot:content>
                                        <livewire:project.shared.get-logs :server="$server"
                                            container="coolify-sentinel" displayName="Sentinel" :collapsible="false"
                                            lazy />
                                    </x-slot:content>
                                    <x-forms.button @click="slideOverOpen=true">{{ __('common.logs') }}</x-forms.button>
                                </x-slide-over>
                            @endif
                        </div>
                    @endif
                </div>
                <div class="flex flex-col gap-2">
                    <div class="w-96">
                        <x-forms.checkbox canGate="update" :canResource="$server" wire:model.live="isSentinelEnabled"
                            label="{{ __('server.enable_sentinel') }}" />
                        @if ($server->isSentinelEnabled())
                            @if (isDev())
                                <x-forms.checkbox canGate="update" :canResource="$server" id="isSentinelDebugEnabled"
                                    label="{{ __('server.enable_sentinel_debug') }}" instantSave />
                            @endif
                            <x-forms.checkbox canGate="update" :canResource="$server" instantSave
                                id="isMetricsEnabled" label="{{ __('server.enable_metrics') }}" />
                        @else
                            @if (isDev())
                                <x-forms.checkbox id="isSentinelDebugEnabled" label="{{ __('server.enable_sentinel_debug') }}"
                                    disabled instantSave />
                            @endif
                            <x-forms.checkbox instantSave disabled id="isMetricsEnabled"
                                label="{{ __('server.enable_metrics_first') }}" />
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
                                label="{{ __('server.custom_sentinel_docker_image') }}"
                                helper="{{ __('server.custom_sentinel_docker_image_helper') }}" />
                        </div>
                    @endif
                    @if ($server->isSentinelEnabled())
                        <div class="flex flex-wrap gap-2 sm:flex-nowrap items-end">
                            <x-forms.input canGate="update" :canResource="$server" type="password" id="sentinelToken"
                                label="{{ __('server.sentinel_token') }}" required helper="{{ __('server.sentinel_token_helper') }}" />
                            <x-forms.button canGate="update" :canResource="$server"
                                wire:click="regenerateSentinelToken">{{ __('button.regenerate') }}</x-forms.button>
                        </div>

                        <x-forms.input canGate="update" :canResource="$server" id="sentinelCustomUrl" required
                            label="{{ __('server.coolify_url') }}"
                            helper="{{ __('server.coolify_url_helper') }}" />

                        <div class="flex flex-col gap-2">
                            <div class="flex flex-wrap gap-2 sm:flex-nowrap">
                                <x-forms.input canGate="update" :canResource="$server"
                                    id="sentinelMetricsRefreshRateSeconds" label="{{ __('server.metrics_rate_seconds') }}" required
                                    helper="{{ __('server.metrics_rate_seconds_helper') }}" />
                                <x-forms.input canGate="update" :canResource="$server" id="sentinelMetricsHistoryDays"
                                    label="{{ __('server.metrics_history_days') }}" required
                                    helper="{{ __('server.metrics_history_days_helper') }}" />
                                <x-forms.input canGate="update" :canResource="$server"
                                    id="sentinelPushIntervalSeconds" label="{{ __('server.push_interval_seconds') }}" required
                                    helper="{{ __('server.push_interval_seconds_helper') }}" />
                            </div>
                        </div>
                    @endif
                </div>
            </form>
        </div>
    </div>
</div>
