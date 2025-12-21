<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > {{ __('server.docker_cleanup') }} | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }" class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="docker-cleanup" />
        <div class="w-full">
            <form wire:submit='submit'>
                <div>
                    <div class="flex items-center gap-2">
                        <h2>{{ __('server.docker_cleanup') }}</h2>
                        <x-forms.button type="submit" canGate="update" :canResource="$server">{{ __('common.save') }}</x-forms.button>
                        @can('update', $server)
                            <x-modal-confirmation title="{{ __('server.confirm_docker_cleanup') }}" buttonTitle="{{ __('server.trigger_manual_cleanup') }}"
                                isHighlightedButton submitAction="manualCleanup" :actions="[
                                    __('server.cleanup_action_1'),
                                    __('server.cleanup_action_2'),
                                    __('server.cleanup_action_3'),
                                    __('server.cleanup_action_4'),
                                    __('server.cleanup_action_5'),
                                    __('server.cleanup_action_6'),
                                ]" :confirmWithText="false"
                                :confirmWithPassword="false" step2ButtonText="{{ __('server.trigger_docker_cleanup') }}" />
                        @endcan
                    </div>
                    <div class="mt-1 mb-6">{{ __('server.docker_cleanup_desc') }}</div>
                </div>

                <div class="flex flex-col gap-2">
                    <div class="flex gap-4">
                        <h3>{{ __('server.cleanup_configuration') }}</h3>
                    </div>
                    <div class="flex items-center gap-4">
                        <x-forms.input canGate="update" :canResource="$server" placeholder="*/10 * * * *"
                            id="dockerCleanupFrequency" label="{{ __('server.docker_cleanup_frequency') }}" required
                            helper="{{ __('server.docker_cleanup_frequency_helper') }}" />
                        @if (!$forceDockerCleanup)
                            <x-forms.input canGate="update" :canResource="$server" id="dockerCleanupThreshold"
                                label="{{ __('server.docker_cleanup_threshold') }}" required
                                helper="{{ __('server.docker_cleanup_threshold_helper') }}" />
                        @endif
                    </div>
                    <div class="w-full sm:w-96">
                        <x-forms.checkbox canGate="update" :canResource="$server"
                            helper="{!! __('server.force_docker_cleanup_helper') !!}"
                            instantSave id="forceDockerCleanup" label="{{ __('server.force_docker_cleanup') }}" />
                    </div>

                </div>

                <div class="flex flex-col gap-2 mt-6">
                    <h3>{{ __('common.advanced') }}</h3>
                    <x-callout type="warning" title="{{ __('common.caution') }}">
                        <p>{{ __('server.caution_text') }}</p>
                    </x-callout>
                    <div class="w-full sm:w-96">
                        <x-forms.checkbox canGate="update" :canResource="$server" instantSave id="deleteUnusedVolumes"
                            label="{{ __('server.delete_unused_volumes') }}"
                            helper="{!! __('server.delete_unused_volumes_helper') !!}" />
                        <x-forms.checkbox canGate="update" :canResource="$server" instantSave id="deleteUnusedNetworks"
                            label="{{ __('server.delete_unused_networks') }}"
                            helper="{!! __('server.delete_unused_networks_helper') !!}" />
                        <x-forms.checkbox canGate="update" :canResource="$server" instantSave
                            id="disableApplicationImageRetention"
                            label="{{ __('server.disable_image_retention') }}"
                            helper="{!! __('server.disable_image_retention_helper') !!}" />
                    </div>
                </div>
            </form>

            <div class="mt-8">
                <h3 class="mb-4">{{ __('server.recent_executions') }} <span class="text-xs text-neutral-500">{{ __('server.click_to_check_output') }}</span></h3>
                <livewire:server.docker-cleanup-executions :server="$server" />
            </div>
        </div>
    </div>
</div>
