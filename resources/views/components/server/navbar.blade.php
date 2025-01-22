<div class="pb-6">
    <x-modal modalId="startProxy">
        <x-slot:modalBody>
            <livewire:activity-monitor header="Proxy Startup Logs" />
        </x-slot:modalBody>
        <x-slot:modalSubmit>
            <x-forms.button onclick="startProxy.close()" type="submit">
                Close
            </x-forms.button>
        </x-slot:modalSubmit>
    </x-modal>
    <div class="flex items-center gap-2">
        <h1>Server</h1>
        @if ($server->proxySet())
            <livewire:server.proxy.status :server="$server" />
        @endif
    </div>
    <div class="subtitle">{{ data_get($server, 'name') }}</div>
    <div class="navbar-main">
        <nav class="flex items-center gap-6 overflow-x-scroll sm:overflow-x-hidden scrollbar min-h-10 whitespace-nowrap">
            <a wire:navigate class="{{ request()->routeIs('server.show') ? 'dark:text-white' : '' }}"
                href="{{ route('server.show', [
                    'server_uuid' => data_get($server, 'uuid'),
                ]) }}">
                <button>Configuration</button>
            </a>

            @if (!$server->isSwarmWorker() && !$server->settings->is_build_server)
                <a wire:navigate class="{{ request()->routeIs('server.proxy') ? 'dark:text-white' : '' }}"
                    href="{{ route('server.proxy', [
                        'server_uuid' => data_get($server, 'uuid'),
                    ]) }}">
                    <button>Proxy</button>
                </a>
            @endif
            <a wire:navigate class="{{ request()->routeIs('server.resources') ? 'dark:text-white' : '' }}"
                href="{{ route('server.resources', [
                    'server_uuid' => data_get($server, 'uuid'),
                ]) }}">
                <button>Resources</button>
            </a>
            <a class="{{ request()->routeIs('server.command') ? 'dark:text-white' : '' }}"
                href="{{ route('server.command', [
                    'server_uuid' => data_get($server, 'uuid'),
                ]) }}">
                <button>Terminal</button>
            </a>
        </nav>
        <div class="order-first sm:order-last">
            <livewire:server.proxy.deploy :server="$server" />
        </div>

    </div>
</div>
