@if (Str::of($complexStatus)->startsWith('running'))
    <x-status.running :status="$complexStatus" />
@elseif(Str::of($complexStatus)->startsWith('restarting'))
    <x-status.restarting :status="$complexStatus" />
@elseif(Str::of($complexStatus)->startsWith('degraded'))
    <x-status.degraded :status="$complexStatus" />
@else
    <x-status.stopped :status="$complexStatus" />
@endif
