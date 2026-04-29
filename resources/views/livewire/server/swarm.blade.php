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
                    <h2>Swarm</h2>
                    <x-deprecated-badge />
                </div>
                <x-callout type="warning" title="Deprecated" class="my-4">
                    {{ config('deprecations.swarm') }}
                </x-callout>
                <div class="pb-4">Read the docs <a class='underline dark:text-white'
                        href='https://coolify.io/docs/knowledge-base/docker/swarm' target='_blank'>here</a>.
                </div>
            </div>

            <div class="w-96">
                @if ($server->settings->is_swarm_worker)
                    <x-forms.checkbox disabled instantSave type="checkbox" id="isSwarmManager"
                        helper="For more information, please read the documentation <a class='dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/swarm' target='_blank'>here</a>."
                        label="Is it a Swarm Manager?" />
                @else
                    <x-forms.checkbox canGate="update" :canResource="$server" instantSave
                        type="checkbox" id="isSwarmManager"
                        helper="For more information, please read the documentation <a class='dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/swarm' target='_blank'>here</a>."
                        label="Is it a Swarm Manager?" />
                @endif

                @if ($server->settings->is_swarm_manager)
                    <x-forms.checkbox disabled instantSave type="checkbox" id="isSwarmWorker"
                        helper="For more information, please read the documentation <a class='dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/swarm' target='_blank'>here</a>."
                        label="Is it a Swarm Worker?" />
                @else
                    <x-forms.checkbox canGate="update" :canResource="$server" instantSave
                        type="checkbox" id="isSwarmWorker"
                        helper="For more information, please read the documentation <a class='dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/swarm' target='_blank'>here</a>."
                        label="Is it a Swarm Worker?" />
                @endif
            </div>
        </div>
    </div>
</div>
