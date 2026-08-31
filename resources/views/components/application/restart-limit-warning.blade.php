@props(['application'])

@if ($application->stoppedAfterRestartLimit())
    @php($restartLimit = method_exists($application, 'restartLimitMaximum') ? $application->restartLimitMaximum() : ($application->max_restart_count ?? 0))
    @php($displayRestartCount = max($application->restart_count ?? 0, $restartLimit))
    <x-status-badge
        status="Restart limit reached"
        type="warning"
        title="Container has crashed and Coolify stopped it after {{ $displayRestartCount }} restart attempts." />
@endif
