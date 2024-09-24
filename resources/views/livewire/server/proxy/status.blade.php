<div x-init="$wire.checkProxy()" class="flex gap-2">
    @if ($server->proxySet())
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
            @else
                <x-status.stopped status="Proxy Not Configured" />
            @endif
        </div>
        <x-forms.button wire:click='checkProxy(true)'>Refresh</x-forms.button>
    @endif
</div>