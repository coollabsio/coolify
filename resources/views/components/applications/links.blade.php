@php
    $hasLinks =
        (data_get($application, 'fqdn') ||
            collect(json_decode($application->docker_compose_domains))->contains(
                fn ($fqdn) => !empty(data_get($fqdn, 'domain')),
            ) ||
            data_get($application, 'previews', collect([]))->count() > 0 ||
            data_get($application, 'ports_mappings_array')) &&
        data_get($application, 'settings.is_raw_compose_deployment_enabled') !== true;

    $linkItemClasses =
        'flex items-center gap-2 px-3 h-8 text-[13px] transition-colors text-neutral-600 dark:text-fg-dim hover:bg-neutral-100 dark:hover:bg-white/[0.06] hover:text-black dark:hover:text-fg';
@endphp

<div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
    <button type="button" @click="open = !open" @click.outside="open = false" title="Open application links"
        class="app-tab shrink-0 gap-1">
        Links
        <svg class="size-3.5 shrink-0 opacity-60" viewBox="0 0 24 24" fill="none">
            <path d="M8 9l4-4 4 4M8 15l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"
                stroke-linejoin="round" />
        </svg>
    </button>
    <div x-show="open" x-cloak x-transition.opacity.duration.120ms
        class="absolute left-0 z-[90] mt-2 min-w-60 max-w-96 max-h-80 overflow-y-auto rounded-lg border border-neutral-200 bg-white py-1.5 shadow-modal scrollbar dark:border-white/10 dark:bg-raised">
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
                                <x-external-link class="size-3.5 shrink-0" />
                                <span class="min-w-0 truncate">{{ getFqdnWithoutPort($domain) }}</span>
                            </a>
                        @endforeach
                    @endif
                @endforeach
            @endif
            @if (data_get($application, 'fqdn'))
                @foreach (str(data_get($application, 'fqdn'))->explode(',') as $fqdn)
                    <a class="{{ $linkItemClasses }}" target="_blank" href="{{ getFqdnWithoutPort($fqdn) }}">
                        <x-external-link class="size-3.5 shrink-0" />
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
                                        <x-external-link class="size-3.5 shrink-0" />
                                        <span class="min-w-0 truncate">PR{{ data_get($preview, 'pull_request_id') }} | {{ getFqdnWithoutPort($domain) }}</span>
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
                                <x-external-link class="size-3.5 shrink-0" />
                                <span class="min-w-0 truncate">PR{{ data_get($preview, 'pull_request_id') }} | {{ data_get($preview, 'fqdn') }}</span>
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
                            <x-external-link class="size-3.5 shrink-0" />
                            <span class="min-w-0 truncate">Port {{ $port }}</span>
                        </a>
                    @else
                        <a class="{{ $linkItemClasses }}" target="_blank"
                            href="http://{{ $application->destination->server->ip }}:{{ explode(':', $port)[0] }}">
                            <x-external-link class="size-3.5 shrink-0" />
                            <span class="min-w-0 truncate">{{ $application->destination->server->ip }}:{{ explode(':', $port)[0] }}</span>
                        </a>
                        @if (count($application->additional_servers) > 0)
                            @foreach ($application->additional_servers as $server)
                                <a class="{{ $linkItemClasses }}" target="_blank"
                                    href="http://{{ $server->ip }}:{{ explode(':', $port)[0] }}">
                                    <x-external-link class="size-3.5 shrink-0" />
                                    <span class="min-w-0 truncate">{{ $server->ip }}:{{ explode(':', $port)[0] }}</span>
                                </a>
                            @endforeach
                        @endif
                    @endif
                @endforeach
            @endif
        @else
            <div class="px-3 py-1.5 text-[13px] text-neutral-500 dark:text-fg-dim">No links available</div>
        @endif
    </div>
</div>
