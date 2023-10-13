<div>
    @if ($server->isFunctional())
        <div class="flex gap-2" @if ($polling) wire:poll.2000ms='checkProxy' @endif>
            @if (data_get($server, 'proxy.status') === 'running')
                <x-status.running status="Proxy Running" />
            @elseif (data_get($server, 'proxy.status') === 'restarting')
                <x-status.restarting status="Proxy Restarting" />
            @else
                <x-status.stopped status="Proxy Stopped" />
            @endif
            @if (data_get($server, 'proxy.status') === 'running')
                <x-forms.button wire:click='checkProxy(true)'>Refresh</x-forms.button>
            @endif
        </div>
    @endif
</div>
