@props([
    'status' => 'Restarting',
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
@endphp
@if ($lastDeploymentLink)
    <x-status-badge as="a" href="{{ $lastDeploymentLink }}" target="_blank" status="{{ $badgeStatus }}" type="warning"
        title="{{ $title }}" class="cursor-pointer underline" />
@else
    <x-status-badge status="{{ $badgeStatus }}" type="warning" title="{{ $title }}" />
@endif
