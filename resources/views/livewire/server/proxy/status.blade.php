<div wire:poll.10000ms="get_status" x-init="$wire.get_status">
    @if ($server->proxy->status === 'running')
        <x-status.running text="Proxy Running" />
    @elseif ($server->proxy->status === 'restarting')
        <x-status.restarting text="Proxy Restarting" />
    @else
        <x-status.stopped text="Proxy Stopped" />
    @endif
</div>
