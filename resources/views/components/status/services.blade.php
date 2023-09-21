@if ($complexStatus === 'running')
    <x-status.running />
@elseif($complexStatus === 'restarting')
    <x-status.restarting />
@elseif($complexStatus === 'degraded')
    <x-status.degraded />
@else
    <x-status.stopped />
@endif
