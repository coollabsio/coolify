@props([
    'status' => 'Restarting',
    'title' => null,
    'lastDeploymentLink' => null,
    'noLoading' => false,
])
@php
    // Handle both colon format (backend) and parentheses format (from services.blade.php)
    // starting:unknown → Starting (unknown)
    // starting (unknown) → starting (unknown) (already formatted, display as-is)

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
<div class="flex items-center">
    @if (!$noLoading)
        <x-loading wire:loading.delay.longer />
    @endif
    <span wire:loading.remove.delay.longer class="flex items-center">
        <div class="badge badge-warning"></div>
        <div class="pl-2 pr-1 text-xs font-bold dark:text-warning" @if($title) title="{{$title}}" @endif>
           @if ($lastDeploymentLink)
              <a href="{{ $lastDeploymentLink }}" target="_blank" class="underline cursor-pointer">
                  {{ $displayStatus }}
              </a>
          @else
              {{ $displayStatus }}
          @endif
        </div>
        @if ($healthStatus && !str($displayStatus)->contains('('))
            <div class="text-xs dark:text-warning">({{ $healthStatus }})</div>
        @endif
    </span>
</div>
