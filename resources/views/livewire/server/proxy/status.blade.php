<div>
    @if ($server->isFunctional())
        <div class="flex gap-2" x-init="$wire.getProxyStatus">
            @if (data_get($server, 'proxy.status') === 'running')
                <x-status.running status="Proxy Running" />
            @elseif (data_get($server, 'proxy.status') === 'restarting')
                <x-status.restarting status="Proxy Restarting" />
            @else
                <x-status.stopped status="Proxy Stopped" />
            @endif
            <x-forms.button wire:click='getProxyStatusWithNoti'>Refresh </x-forms.button>
        </div>
    @endif
</div>
