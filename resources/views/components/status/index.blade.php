@if ($status === 'running')
    <x-status.running />
@elseif($status === 'restarting')
    <x-status.restarting />
@else
    <x-status.stopped />
@endif
