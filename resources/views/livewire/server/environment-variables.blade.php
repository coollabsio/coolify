<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Environment Variables | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div x-data class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="environment-variables" />
        <div class="w-full">
            <div>
                <div class="flex items-center gap-2">
                    <h2>Environment Variables</h2>
                </div>
                <div class="mb-4">
                    Environment variables that are automatically injected into all applications and services deployed on this server.
                    Application-level variables take precedence over server-level variables.
                </div>
            </div>

            <div class="flex flex-col gap-4">
                <div class="mb-4 p-4 rounded dark:bg-coolgray-100">
                    <h4 class="mb-2">Auto-injected Variables</h4>
                    <div class="text-sm dark:text-neutral-400">
                        The following variables are automatically available in every container on this server:
                    </div>
                    <div class="mt-2 font-mono text-sm">
                        <div><span class="dark:text-warning">COOLIFY_SERVER_UUID</span>={{ $server->uuid }}</div>
                        <div><span class="dark:text-warning">COOLIFY_SERVER_IP</span>={{ $server->ip }}</div>
                        <div><span class="dark:text-warning">COOLIFY_SERVER_NAME</span>={{ $server->name }}</div>
                    </div>
                </div>

                @can('update', $server)
                    <form wire:submit='submit' class="flex flex-col gap-2">
                        <h3>Add New Variable</h3>
                        <div class="flex flex-wrap gap-2 sm:flex-nowrap">
                            <x-forms.input id="newKey" label="Key" placeholder="VARIABLE_NAME" required />
                            <x-forms.input id="newValue" label="Value" placeholder="value" type="password" />
                        </div>
                        <div>
                            <x-forms.button type="submit">Add</x-forms.button>
                        </div>
                    </form>
                @endcan

                <div class="flex flex-col gap-2">
                    <h3>Current Variables</h3>
                    @forelse ($this->environmentVariables as $env)
                        <livewire:server.environment-variable-item
                            wire:key="server-env-{{ $env->id }}"
                            :env="$env"
                            :server="$server" />
                    @empty
                        <div class="dark:text-neutral-400">No custom environment variables configured.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
