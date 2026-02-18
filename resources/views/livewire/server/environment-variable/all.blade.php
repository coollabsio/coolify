<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Environment Variables | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex items-center gap-2">
        <h2>Environment Variables</h2>
        <x-slide-over>
            <x-slot:title>New Environment Variable</x-slot:title>
            <x-slot:content>
                <livewire:server.environment-variable.add />
            </x-slot:content>
            <x-slot:button-title>
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" stroke-width="1.5"
                    stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                    <path d="M12 5l0 14" />
                    <path d="M5 12l14 0" />
                </svg>
                Add
            </x-slot:button-title>
        </x-slide-over>
    </div>
    <div class="mb-4 text-sm text-neutral-500 dark:text-neutral-400">
        These environment variables will be injected into every application deployed on this server.
        Application-level variables with the same key will take precedence.
    </div>
    <div class="mb-2 text-sm text-neutral-500 dark:text-neutral-400">
        Additionally, <code class="text-xs">COOLIFY_SERVER_NAME</code> and <code class="text-xs">COOLIFY_SERVER_UUID</code>
        are automatically available in all deployments.
    </div>
    <div class="flex flex-col gap-2">
        @forelse ($this->environmentVariables as $env)
            <livewire:server.environment-variable.show :env="$env" :key="$env->id" />
        @empty
            <div class="text-neutral-500 dark:text-neutral-400">
                No environment variables defined for this server.
            </div>
        @endforelse
    </div>
</div>
