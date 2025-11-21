@props([
    'status' => 'Running',
    'title' => null,
    'lastDeploymentLink' => null,
    'noLoading' => false,
])
@php
    // Handle both colon format (backend) and parentheses format (from services.blade.php)
    // running:healthy → Running (healthy)
    // running (healthy) → running (healthy) (already formatted, display as-is)

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
    <div class="flex items-center">
        <div wire:loading.delay.longer wire:target="checkProxy(true)" class="badge badge-warning"></div>
        <div wire:loading.remove.delay.longer wire:target="checkProxy(true)" class="badge badge-success"></div>
        <div class="pl-2 pr-1 text-xs font-bold text-success"
            @if ($title) title="{{ $title }}" @endif>
            @if ($lastDeploymentLink)
                <a href="{{ $lastDeploymentLink }}" target="_blank" class="underline cursor-pointer">
                    {{ $displayStatus }}
                </a>
            @else
                {{ $displayStatus }}
            @endif
        </div>
        @if ($healthStatus && !str($displayStatus)->contains('('))
            <div class="text-xs text-success">({{ $healthStatus }})</div>
        @endif
        @php
            $showUnknownHelper =
                !str($status)->startsWith('Proxy') &&
                (str($status)->contains('unknown') || str($healthStatus)->contains('unknown'));
            $showUnhealthyHelper =
                !str($status)->startsWith('Proxy') &&
                (str($status)->contains('unhealthy') || str($healthStatus)->contains('unhealthy'));
        @endphp
        @if ($showUnknownHelper)
        <div class="px-2">
            <x-helper
                helper="No health check configured. <span class='dark:text-warning text-coollabs'>The resource may be functioning normally.</span><br><br>Traefik and Caddy will route traffic to this container even without a health check. However, configuring a health check is recommended to ensure the resource is ready before receiving traffic.<br><br>More details in the <a href='https://coolify.io/docs/knowledge-base/proxy/traefik/healthchecks' class='underline dark:text-warning text-coollabs' target='_blank'>documentation</a>.">
                <x-slot:icon>
                    <svg class="hidden w-4 h-4 dark:text-warning lg:block" viewBox="0 0 256 256"
                        xmlns="http://www.w3.org/2000/svg">
                        <path fill="currentColor"
                            d="M240.26 186.1L152.81 34.23a28.74 28.74 0 0 0-49.62 0L15.74 186.1a27.45 27.45 0 0 0 0 27.71A28.31 28.31 0 0 0 40.55 228h174.9a28.31 28.31 0 0 0 24.79-14.19a27.45 27.45 0 0 0 .02-27.71m-20.8 15.7a4.46 4.46 0 0 1-4 2.2H40.55a4.46 4.46 0 0 1-4-2.2a3.56 3.56 0 0 1 0-3.73L124 46.2a4.77 4.77 0 0 1 8 0l87.44 151.87a3.56 3.56 0 0 1 .02 3.73M116 136v-32a12 12 0 0 1 24 0v32a12 12 0 0 1-24 0m28 40a16 16 0 1 1-16-16a16 16 0 0 1 16 16">
                        </path>
                    </svg>
                </x-slot:icon>
            </x-helper>
        </div>
        @endif
        @if ($showUnhealthyHelper)
         <div class="px-2">
            <x-helper
                helper="Unhealthy state. <span class='dark:text-warning text-coollabs'>The health check is failing.</span><br><br>This resource will <span class='dark:text-warning text-coollabs'>NOT work with Traefik</span> as it expects a healthy state. Your action is required to fix the health check or the underlying issue causing it to fail.<br><br>More details in the <a href='https://coolify.io/docs/knowledge-base/proxy/traefik/healthchecks' class='underline dark:text-warning text-coollabs' target='_blank'>documentation</a>.">
                <x-slot:icon>
                    <svg class="hidden w-4 h-4 dark:text-warning lg:block" viewBox="0 0 256 256"
                        xmlns="http://www.w3.org/2000/svg">
                        <path fill="currentColor"
                            d="M240.26 186.1L152.81 34.23a28.74 28.74 0 0 0-49.62 0L15.74 186.1a27.45 27.45 0 0 0 0 27.71A28.31 28.31 0 0 0 40.55 228h174.9a28.31 28.31 0 0 0 24.79-14.19a27.45 27.45 0 0 0 .02-27.71m-20.8 15.7a4.46 4.46 0 0 1-4 2.2H40.55a4.46 4.46 0 0 1-4-2.2a3.56 3.56 0 0 1 0-3.73L124 46.2a4.77 4.77 0 0 1 8 0l87.44 151.87a3.56 3.56 0 0 1 .02 3.73M116 136v-32a12 12 0 0 1 24 0v32a12 12 0 0 1-24 0m28 40a16 16 0 1 1-16-16a16 16 0 0 1 16 16">
                        </path>
                    </svg>
                </x-slot:icon>
            </x-helper>
        </div>
        @endif
    </div>

</div>
