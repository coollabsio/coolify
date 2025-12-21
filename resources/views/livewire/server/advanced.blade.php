<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > {{ __('common.advanced') }} | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }" class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="advanced" />
        <form wire:submit='submit' class="w-full">
            <div>
                <div class="flex items-center gap-2">
                    <h2>{{ __('common.advanced') }}</h2>
                    <x-forms.button canGate="update" :canResource="$server" type="submit">{{ __('common.save') }}</x-forms.button>
                </div>
                <div class="mb-4">{{ __('server.advanced_config_desc') }}</div>
            </div>

            <h3>{{ __('server.disk_usage') }}</h3>
            <div class="flex flex-col gap-6">
                <div class="flex flex-col">
                    <div class="flex flex-wrap gap-2 sm:flex-nowrap pt-4">
                        <x-forms.input canGate="update" :canResource="$server" placeholder="0 23 * * *"
                            id="serverDiskUsageCheckFrequency" label="{{ __('server.disk_usage_check_frequency') }}" required
                            helper="{{ __('server.disk_usage_check_frequency_helper') }}" />
                        <x-forms.input canGate="update" :canResource="$server" id="serverDiskUsageNotificationThreshold"
                            label="{{ __('server.disk_usage_threshold') }}" required
                            helper="{{ __('server.disk_usage_threshold_helper') }}" />
                    </div>
                </div>

                <div class="flex flex-col">
                    <h3>{{ __('server.builds') }}</h3>
                    <div class="flex flex-wrap gap-2 sm:flex-nowrap pt-4">
                        <x-forms.input canGate="update" :canResource="$server" id="concurrentBuilds"
                            label="{{ __('server.concurrent_builds') }}" required
                            helper="{{ __('server.concurrent_builds_helper') }}" />
                        <x-forms.input canGate="update" :canResource="$server" id="dynamicTimeout"
                            label="{{ __('server.deployment_timeout') }}" required
                            helper="{{ __('server.deployment_timeout_helper') }}" />
                        <x-forms.input canGate="update" :canResource="$server" id="deploymentQueueLimit"
                            label="{{ __('server.deployment_queue_limit') }}" required
                            helper="{{ __('server.deployment_queue_limit_helper') }}" />
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
