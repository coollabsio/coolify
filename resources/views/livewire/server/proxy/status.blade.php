<div wire:poll.10000ms="get_status" x-init="$wire.get_status">
    @if ($server->proxy->status === 'running')
        <x-status.running />
    @elseif ($server->proxy->status === 'restarting')
        <x-status.restarting />
    @else
        <x-status.stopped />
    @endif
</div>
