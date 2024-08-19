<div x-init="$wire.checkProxy()" x-on:refresh-proxy-status.window="$wire.getProxyStatus()" x-on:proxyStatusRefreshed.window="$wire.proxyStatusUpdated()">
    @if (
        $server->proxyType() !== 'NONE' &&
        $server->isFunctional() &&
        !$server->isSwarmWorker() &&
        !$server->settings->is_build_server
    )
        <div class="flex gap-2">
            @if ($server->proxy)
                @if ($server->proxy->status === 'Proxy Running')
                    <x-status.running status="Proxy Running" />
                @elseif ($server->proxy->status === 'Proxy Restarting')
                    <x-status.restarting status="Proxy Restarting" />
                @elseif ($server->proxy->status === 'Proxy Stopped')
                    <x-status.stopped status="Proxy Stopped" />
                @else
                    <x-status.stopped status="Proxy Exited" />
                @endif
                @if ($server->proxy->status === 'Proxy Running')
                    <x-forms.button wire:click='checkProxy(true)'>Refresh</x-forms.button>
                @endif
            @else
                <x-status.stopped status="Proxy Not Configured" />
            @endif
        </div>
    @endif
</div>