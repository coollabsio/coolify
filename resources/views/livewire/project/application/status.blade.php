<div wire:poll.10000ms='applicationStatusChanged'>
    @if ($application->status === 'running')
        <x-status.running />
    @elseif($application->status === 'restarting')
        <x-status.restarting />
    @else
        <x-status.stopped />
    @endif
</div>
