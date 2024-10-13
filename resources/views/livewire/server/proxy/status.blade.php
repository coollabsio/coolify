<div x-init="$wire.checkProxy()" class="flex gap-2">
    @if (data_get($server, 'proxy.status') === 'running')
        <x-status.running status="Proxy Running" noLoading />
    @elseif (data_get($server, 'proxy.status') === 'restarting')
        <x-status.restarting status="Proxy Restarting" noLoading />
    @elseif (data_get($server, 'proxy.force_stop'))
        <x-status.stopped status="Proxy Stopped" noLoading />
    @elseif (data_get($server, 'proxy.status') === 'exited')
        <x-status.stopped status="Proxy Exited" noLoading />
    @else
        <x-status.stopped status="Proxy Not Running" noLoading />
    @endif
    <x-forms.button wire:click='checkProxy(true)'>Refresh</x-forms.button>
</div>
