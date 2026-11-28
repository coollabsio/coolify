{{-- The v4 service log page: one block for each container, collapsed when there are several. --}}
<div class="flex w-full flex-col gap-4">
    @php($blocks = collect($services)->flatMap(fn (array $service): array => collect($service['servers'])->map(fn ($server): array => ['service' => $service, 'server' => $server])->all()))
    @forelse ($blocks as $block)
        <livewire:project.shared.get-logs wire:key="cluster-stack-logs-{{ $block['service']['uuid'] }}-{{ $block['server']->uuid }}"
            :server="$block['server']" :resource="$workloads->firstWhere('uuid', $block['service']['uuid'])"
            :container="'coolify-'.$block['service']['uuid'].'-main'"
            :displayName="$block['service']['service'].(collect($services)->sum(fn ($service) => count($service['servers'])) > count($services) ? ' ('.$block['server']->name.')' : '')"
            :expandByDefault="$blocks->count() === 1" />
    @empty
        <x-empty size="lg" title="Runtime logs unavailable"
            description="No service of this stack is placed on a server, so there are no runtime logs to show."
            icon-name="file-content" />
    @endforelse
</div>
