<x-application.settings-section title="Terminal" description="Open a terminal in the container of a service.">
    <div class="flex flex-col divide-y divide-neutral-200 dark:divide-white/[0.07]">
        @foreach ($services as $service)
            <div wire:key="cluster-stack-terminal-{{ $service['uuid'] }}" class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <h4 class="truncate text-sm font-semibold text-black dark:text-fg">{{ str($service['service'])->headline() }}</h4>
                    <p class="text-xs text-neutral-500 dark:text-fg-dim">{{ ucfirst($service['status']) }}@if ($service['server']), {{ $service['server'] }}@endif</p>
                </div>
                @if ($service['running'])
                    <a class="button shrink-0" href="{{ $service['terminalHref'] }}">Open terminal</a>
                @else
                    <span class="text-xs text-neutral-500 dark:text-fg-dim">Not running</span>
                @endif
            </div>
        @endforeach
    </div>
</x-application.settings-section>
