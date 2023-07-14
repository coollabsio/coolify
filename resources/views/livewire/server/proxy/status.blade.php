<div wire:poll.10000ms="proxyStatus" x-init="$wire.proxyStatus">
    @if ($server->proxy->status === 'running')
        <x-status.running />
    @elseif ($server->proxy->status === 'restarting')
        <x-status.restarting />
    @else
        <x-status.stopped />
    @endif
</div>
