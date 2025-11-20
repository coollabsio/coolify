@props([
    'status' => 'Degraded',
])
@php
    // Handle both colon format (backend) and parentheses format (from services.blade.php)
    // degraded:unhealthy → Degraded (unhealthy)
    // degraded (unhealthy) → degraded (unhealthy) (already formatted, display as-is)

    if (str($status)->contains('(')) {
        // Already in parentheses format from services.blade.php - use as-is
        $displayStatus = $status;
        $healthStatus = str($status)->after('(')->before(')')->trim()->value();
    } elseif (str($status)->contains(':') && !str($status)->startsWith('Proxy')) {
        // Colon format from backend - transform it
        $parts = explode(':', $status);
        $displayStatus = str($parts[0])->headline();
        $healthStatus = $parts[1] ?? null;
    } else {
        // Simple status without health
        $displayStatus = str($status)->headline();
        $healthStatus = null;
    }
@endphp
<div class="flex items-center" >
    <x-loading wire:loading.delay.longer />
    <span wire:loading.remove.delay.longer class="flex items-center">
        <div class="badge badge-warning"></div>
        <div class="pl-2 pr-1 text-xs font-bold dark:text-warning">{{ $displayStatus }}</div>
        @if ($healthStatus && !str($displayStatus)->contains('('))
            <div class="text-xs dark:text-warning">({{ $healthStatus }})</div>
        @endif
    </span>
</div>
