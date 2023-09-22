@if (Str::of($status)->startsWith('running'))
    <x-status.running :status="$status" />
@elseif(Str::of($status)->startsWith('restarting'))
    <x-status.restarting :status="$status" />
@else
    <x-status.stopped :status="$status" />
@endif
