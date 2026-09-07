@props(['application', 'fullWidth' => false, 'compact' => false])

@php
    $hasLinks =
        (data_get($application, 'fqdn') ||
            collect(json_decode($application->docker_compose_domains))->contains(
                fn ($fqdn) => !empty(data_get($fqdn, 'domain')),
            ) ||
            data_get($application, 'previews', collect([]))->count() > 0 ||
            data_get($application, 'ports_mappings_array')) &&
        data_get($application, 'settings.is_raw_compose_deployment_enabled') !== true;

    $linkItemClasses = 'listbox-option justify-start! gap-2.5!';
@endphp

<div @class([
    'relative' => !$compact,
    'static' => $compact,
    'w-full' => $fullWidth,
]) x-data="{ open: false }"
    x-effect="$dispatch('resource-actions-toggled', { open })" @keydown.escape.window="open = false">
    <button type="button" @click="open = !open" @click.outside="open = false" title="Open application links"
        @class([
            'app-tab shrink-0 gap-1' => !$fullWidth && !$compact,
            'button w-full justify-between' => $fullWidth,
            'inline-flex h-6 shrink-0 items-center gap-1.5 rounded-full border border-neutral-200 bg-neutral-100 px-2 text-xs font-medium leading-none text-neutral-700 dark:border-white/[0.12] dark:bg-white/[0.07] dark:text-white' => $compact,
        ])>
        <span class="inline-flex items-center gap-2">
            @unless ($compact)
                <x-reicon name="external-link" class="size-3.5 shrink-0 opacity-70" />
            @endunless
            Links
        </span>
        <span class="inline-flex transition-transform" :class="open && 'rotate-180'">
            <x-reicon name="chevron-down" class="size-3 opacity-55" />
        </span>
    </button>
    <div x-show="open" x-cloak x-transition.origin.top.right role="menu"
        @class([
            'listbox-panel top-full! mt-1!',
            'left-0! right-0! w-full! min-w-0! max-w-none!' => $fullWidth,
            'left-1/2! right-auto! w-[calc(100vw-2rem)]! max-w-md! min-w-0! -translate-x-1/2' => $compact,
            'right-0! left-auto! min-w-60! max-w-96!' => !$fullWidth && !$compact,
        ])>
        @if ($hasLinks)
            @if (data_get($application, 'gitBrancLocation'))
                <a target="_blank" class="{{ $linkItemClasses }}" href="{{ $application->gitBranchLocation }}">
                    <x-git-icon git="{{ $application->source?->getMorphClass() }}" />
                    <span class="min-w-0 truncate">Git Repository</span>
                </a>
            @endif
            @if (data_get($application, 'build_pack') === 'dockercompose')
                @foreach (collect(json_decode($application->docker_compose_domains)) as $fqdn)
                    @if (data_get($fqdn, 'domain'))
                        @foreach (explode(',', data_get($fqdn, 'domain')) as $domain)
                            <a class="{{ $linkItemClasses }}" target="_blank" href="{{ getFqdnWithoutPort($domain) }}">
                                <span
                                    class="shrink-0 rounded-md bg-success/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-success ring-1 ring-success/20">
                                    Production
                                </span>
                                <span class="min-w-0 truncate">{{ getFqdnWithoutPort($domain) }}</span>
                            </a>
                        @endforeach
                    @endif
                @endforeach
            @endif
            @if (data_get($application, 'fqdn'))
                @foreach (str(data_get($application, 'fqdn'))->explode(',') as $fqdn)
                    <a class="{{ $linkItemClasses }}" target="_blank" href="{{ getFqdnWithoutPort($fqdn) }}">
                        <span
                            class="shrink-0 rounded-md bg-success/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-success ring-1 ring-success/20">
                            Production
                        </span>
                        <span class="min-w-0 truncate">{{ getFqdnWithoutPort($fqdn) }}</span>
                    </a>
                @endforeach
            @endif
            @if (data_get($application, 'previews', collect())->count() > 0)
                @if (data_get($application, 'build_pack') === 'dockercompose')
                    @foreach ($application->previews as $preview)
                        @foreach (collect(json_decode($preview->docker_compose_domains)) as $fqdn)
                            @if (data_get($fqdn, 'domain'))
                                @foreach (explode(',', data_get($fqdn, 'domain')) as $domain)
                                    <a class="{{ $linkItemClasses }}" target="_blank"
                                        href="{{ getFqdnWithoutPort($domain) }}">
                                        <span
                                            class="shrink-0 rounded-md bg-coollabs/10 px-1.5 py-0.5 text-[10px] font-semibold text-coollabs ring-1 ring-coollabs/20 dark:bg-warning/10 dark:text-warning dark:ring-warning/20">
                                            PR #{{ data_get($preview, 'pull_request_id') }}
                                        </span>
                                        <span class="min-w-0 truncate">{{ getFqdnWithoutPort($domain) }}</span>
                                    </a>
                                @endforeach
                            @endif
                        @endforeach
                    @endforeach
                @else
                    @foreach (data_get($application, 'previews') as $preview)
                        @if (data_get($preview, 'fqdn'))
                            <a class="{{ $linkItemClasses }}" target="_blank"
                                href="{{ getFqdnWithoutPort(data_get($preview, 'fqdn')) }}">
                                <span
                                    class="shrink-0 rounded-md bg-coollabs/10 px-1.5 py-0.5 text-[10px] font-semibold text-coollabs ring-1 ring-coollabs/20 dark:bg-warning/10 dark:text-warning dark:ring-warning/20">
                                    PR #{{ data_get($preview, 'pull_request_id') }}
                                </span>
                                <span class="min-w-0 truncate">{{ getFqdnWithoutPort(data_get($preview, 'fqdn')) }}</span>
                            </a>
                        @endif
                    @endforeach
                @endif
            @endif
            @if (data_get($application, 'ports_mappings_array'))
                @foreach ($application->ports_mappings_array as $port)
                    @if ($application->destination->server->id === 0)
                        <a class="{{ $linkItemClasses }}" target="_blank"
                            href="http://localhost:{{ explode(':', $port)[0] }}">
                            <span class="min-w-0 truncate">Port {{ $port }}</span>
                        </a>
                    @else
                        <a class="{{ $linkItemClasses }}" target="_blank"
                            href="http://{{ $application->destination->server->ip }}:{{ explode(':', $port)[0] }}">
                            <span class="min-w-0 truncate">{{ $application->destination->server->ip }}:{{ explode(':', $port)[0] }}</span>
                        </a>
                        @if (count($application->additional_servers) > 0)
                            @foreach ($application->additional_servers as $server)
                                <a class="{{ $linkItemClasses }}" target="_blank"
                                    href="http://{{ $server->ip }}:{{ explode(':', $port)[0] }}">
                                    <span class="min-w-0 truncate">{{ $server->ip }}:{{ explode(':', $port)[0] }}</span>
                                </a>
                            @endforeach
                        @endif
                    @endif
                @endforeach
            @endif
        @else
            <div class="listbox-option justify-start! cursor-default!">No links available</div>
        @endif
    </div>
</div>
