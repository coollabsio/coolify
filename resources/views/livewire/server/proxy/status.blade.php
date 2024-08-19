<div x-init="$wire.checkProxy()">
    @if (
        $server->proxyType() !== 'NONE' &&
            $server->isFunctional() &&
            !$server->isSwarmWorker() &&
            !$server->settings->is_build_server)
        <div class="flex gap-2">
            @if (data_get($server, 'proxy.status') === 'Proxy Running')
                <x-status.running status="Proxy Running" />
            @elseif (data_get($server, 'proxy.status') === 'Proxy Restarting')
                <x-status.restarting status="Proxy Restarting" />
            @elseif (data_get($server, 'proxy.status') === 'Proxy Stopped')
                <x-status.stopped status="Proxy Stopped" />
            @else
                <x-status.stopped status="Proxy Exited" />
            @endif
            @if (data_get($server, 'proxy.status') === 'Proxy Running')
                <x-forms.button wire:click='checkProxy(true)'>Refresh</x-forms.button>
            @endif
        </div>
    @endif
</div>