<x-application.settings-section title="Domains" description="The domains of the services of this stack. Each service manages its own domains, like the applications of a v4 service.">
    <div class="flex flex-col divide-y divide-neutral-200 dark:divide-white/[0.07]">
        @foreach (array_filter($services, fn (array $service): bool => ! $service['isDatabase']) as $service)
            <div wire:key="cluster-stack-domains-{{ $service['uuid'] }}" class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <h4 class="truncate text-sm font-semibold text-black dark:text-fg">{{ str($service['service'])->headline() }}</h4>
                    @forelse ($service['domains'] as $domain)
                        <a href="{{ $domain }}" target="_blank" rel="noopener noreferrer" class="mt-1 block truncate font-mono text-xs text-neutral-500 underline-offset-2 hover:underline dark:text-fg-dim">{{ $domain }}</a>
                    @empty
                        <p class="mt-1 text-xs text-neutral-500 dark:text-fg-dim">No domain.</p>
                    @endforelse
                </div>
                <a class="button shrink-0" {{ wireNavigate() }} href="{{ $service['domainsHref'] }}">Manage domains</a>
            </div>
        @endforeach
    </div>
</x-application.settings-section>
