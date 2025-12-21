<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Swarm | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="swarm" />
        <div class="w-full">
            <div>
                <div class="flex items-center gap-2">
                    <h2>{{ __('server.swarm') }} <span class="text-xs text-neutral-500">{{ __('server.swarm_experimental') }}</span></h2>
                </div>
                <div class="pb-4">{!! __('server.swarm_read_docs') !!}
                </div>
            </div>

            <div class="w-96">
                @if ($server->settings->is_swarm_worker)
                    <x-forms.checkbox disabled instantSave type="checkbox" id="isSwarmManager"
                        helper="{{ __('server.swarm_docs_helper') }}"
                        label="{{ __('server.is_swarm_manager') }}" />
                @else
                    <x-forms.checkbox canGate="update" :canResource="$server" instantSave
                        type="checkbox" id="isSwarmManager"
                        helper="{{ __('server.swarm_docs_helper') }}"
                        label="{{ __('server.is_swarm_manager') }}" />
                @endif

                @if ($server->settings->is_swarm_manager)
                    <x-forms.checkbox disabled instantSave type="checkbox" id="isSwarmWorker"
                        helper="{{ __('server.swarm_docs_helper') }}"
                        label="{{ __('server.is_swarm_worker') }}" />
                @else
                    <x-forms.checkbox canGate="update" :canResource="$server" instantSave
                        type="checkbox" id="isSwarmWorker"
                        helper="{{ __('server.swarm_docs_helper') }}"
                        label="{{ __('server.is_swarm_worker') }}" />
                @endif
            </div>
        </div>
    </div>
</div>
