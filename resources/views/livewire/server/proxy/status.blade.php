<div x-init="$wire.checkProxy()" class="flex gap-2">
    <x-forms.button wire:click='checkProxy(true)' :showLoadingIndicator="false">Refresh</x-forms.button>
    @if (data_get($server, 'proxy.status') === 'running')
        <x-status.running status="Proxy Running" />
    @elseif (data_get($server, 'proxy.status') === 'restarting')
        <x-status.restarting status="Proxy Restarting" />
    @elseif (data_get($server, 'proxy.force_stop'))
        <x-status.stopped status="Proxy Stopped" />
    @elseif (data_get($server, 'proxy.status') === 'exited')
        <x-status.stopped status="Proxy Exited" />
    @else
        <x-status.stopped status="Proxy Not Running" />
    @endif

</div>
