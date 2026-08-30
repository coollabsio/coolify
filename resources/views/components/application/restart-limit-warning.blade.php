@props(['application'])

@if ($application->stoppedAfterRestartLimit())
    @php($displayRestartCount = max($application->restart_count ?? 0, $application->max_restart_count ?? 0))
    <x-status-badge
        status="Restart limit reached"
        type="warning"
        title="Container has crashed and Coolify stopped it after {{ $displayRestartCount }} restart attempts." />
@endif
