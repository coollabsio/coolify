@props([
    'status' => 'Degraded',
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
<x-status-badge status="{{ $badgeStatus }}" type="warning" />
