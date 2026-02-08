<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Environment Variables | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="environment-variables" />
        <div class="w-full">
            <div class="flex flex-col gap-2">
                <div>
                    <h2>Environment Variables</h2>
                    <div>Environment variables defined here will be available in all applications deployed on this
                        server.</div>
                </div>
                <livewire:project.shared.environment-variable.all :resource="$server" />
            </div>
        </div>
    </div>
</div>
