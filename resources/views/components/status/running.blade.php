@props([
    'status' => 'Running',
    'title' => null,
    'lastDeploymentLink' => null,
    'noLoading' => false,
])
@php
    if (str($status)->contains('(')) {
        $displayStatus = $status;
        $healthStatus = null;
    } elseif (str($status)->contains(':') && ! str($status)->startsWith('Proxy')) {
        $parts = explode(':', $status);
        $displayStatus = str($parts[0])->headline()->value();
        $healthStatus = $parts[1] ?? null;
    } else {
        $displayStatus = str($status)->headline()->value();
        $healthStatus = null;
    }

    $badgeStatus = $healthStatus ? "{$displayStatus} ({$healthStatus})" : $displayStatus;
    $showUnknownHelper = ! str($status)->startsWith('Proxy') && (str($status)->contains('unknown') || str($healthStatus)->contains('unknown'));
    $showUnhealthyHelper = ! str($status)->startsWith('Proxy') && (str($status)->contains('unhealthy') || str($healthStatus)->contains('unhealthy'));
@endphp
<div class="flex items-center gap-1">
    @if ($lastDeploymentLink)
        <x-status-badge as="a" href="{{ $lastDeploymentLink }}" target="_blank" status="{{ $badgeStatus }}" type="success"
            title="{{ $title }}" class="cursor-pointer underline" />
    @else
        <x-status-badge status="{{ $badgeStatus }}" type="success" title="{{ $title }}" />
    @endif
    @if ($showUnknownHelper)
        <div>
            <x-helper
                helper="No health check configured. <span class='dark:text-warning text-coollabs'>The resource may be functioning normally.</span><br><br>Traefik and Caddy will route traffic to this container even without a health check. However, configuring a health check is recommended to ensure the resource is ready before receiving traffic.<br><br>More details in the <a href='https://coolify.io/docs/knowledge-base/proxy/traefik/healthchecks' class='underline dark:text-warning text-coollabs' target='_blank'>documentation</a>.">
                <x-slot:icon>
                    <x-status-badge status="No health check" type="warning" class="cursor-help" />
                </x-slot:icon>
            </x-helper>
        </div>
    @endif
    @if ($showUnhealthyHelper)
        <div>
            <x-helper
                helper="Unhealthy state. <span class='dark:text-warning text-coollabs'>The health check is failing.</span><br><br>This resource will <span class='dark:text-warning text-coollabs'>NOT work with Traefik</span> as it expects a healthy state. Your action is required to fix the health check or the underlying issue causing it to fail.<br><br>More details in the <a href='https://coolify.io/docs/knowledge-base/proxy/traefik/healthchecks' class='underline dark:text-warning text-coollabs' target='_blank'>documentation</a>.">
                <x-slot:icon>
                    <x-status-badge status="Unhealthy" type="warning" class="cursor-help" />
                </x-slot:icon>
            </x-helper>
        </div>
    @endif
</div>
