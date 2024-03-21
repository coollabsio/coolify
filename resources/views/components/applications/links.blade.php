<div x-data="{
    dropdownOpen: false
}" class="relative" @click.outside="dropdownOpen = false">

    <button @click="dropdownOpen=true"
        class="inline-flex items-center justify-center py-1 pr-12 text-sm font-medium transition-colors focus:outline-none disabled:opacity-50 disabled:pointer-events-none">
        <span class="flex flex-col items-start flex-shrink-0 h-full ml-2 leading-none translate-y-px">
            Open Application
        </span>
        <svg class="absolute right-0 w-5 h-5 mr-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
            stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round"
                d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
        </svg>  
    </button>

    <div x-show="dropdownOpen" @click.away="dropdownOpen=false" x-transition:enter="ease-out duration-200"
        x-transition:enter-start="-translate-y-2" x-transition:enter-end="translate-y-0"
        class="absolute top-0 z-50 mt-6 min-w-max" x-cloak>
        <div class="p-1 mt-1 dark:bg-coolgray-200">
            @if (
                (data_get($application, 'fqdn') ||
                    collect(json_decode($this->application->docker_compose_domains))->count() > 0 ||
                    data_get($application, 'previews', collect([]))->count() > 0 ||
                    data_get($application, 'ports_mappings_array')) &&
                    data_get($application, 'settings.is_raw_compose_deployment_enabled') !== true)
                @if (data_get($application, 'gitBrancLocation'))
                    <a target="_blank" class="dropdown-item" href="{{ $application->gitBranchLocation }}">
                        <x-git-icon git="{{ $application->source?->getMorphClass() }}" />
                        Git Repository
                    </a>
                @endif
                @if (data_get($application, 'build_pack') === 'dockercompose')
                    @foreach (collect(json_decode($this->application->docker_compose_domains)) as $fqdn)
                        @if (data_get($fqdn, 'domain'))
                            @foreach (explode(',', data_get($fqdn, 'domain')) as $domain)
                                <a class="dropdown-item" target="_blank" href="{{ getFqdnWithoutPort($domain) }}">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24"
                                        stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                        <path d="M9 15l6 -6" />
                                        <path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464" />
                                        <path
                                            d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463" />
                                    </svg>{{ getFqdnWithoutPort($domain) }}
                                </a>
                            @endforeach
                        @endif
                    @endforeach
                @endif
                @if (data_get($application, 'fqdn'))
                    @foreach (str(data_get($application, 'fqdn'))->explode(',') as $fqdn)
                        <a class="dropdown-item" target="_blank" href="{{ getFqdnWithoutPort($fqdn) }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path d="M9 15l6 -6" />
                                <path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464" />
                                <path
                                    d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463" />
                            </svg>{{ getFqdnWithoutPort($fqdn) }}
                        </a>
                    @endforeach
                @endif
                @if (data_get($application, 'previews', collect([]))->count() > 0)
                    @foreach (data_get($application, 'previews') as $preview)
                        @if (data_get($preview, 'fqdn'))
                            <a class="dropdown-item" target="_blank"
                                href="{{ getFqdnWithoutPort(data_get($preview, 'fqdn')) }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24"
                                    stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                    <path d="M9 15l6 -6" />
                                    <path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464" />
                                    <path
                                        d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463" />
                                </svg>
                                PR{{ data_get($preview, 'pull_request_id') }} |
                                {{ data_get($preview, 'fqdn') }}
                            </a>
                        @endif
                    @endforeach
                @endif
                @if (data_get($application, 'ports_mappings_array'))
                    @foreach ($application->ports_mappings_array as $port)
                        @if ($application->destination->server->id === 0)
                            <a class="dropdown-item" target="_blank"
                                href="http://localhost:{{ explode(':', $port)[0] }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24"
                                    stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                    <path d="M9 15l6 -6" />
                                    <path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464" />
                                    <path
                                        d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463" />
                                </svg>
                                Port {{ $port }}
                            </a>
                        @else
                            <a class="dropdown-item" target="_blank"
                                href="http://{{ $application->destination->server->ip }}:{{ explode(':', $port)[0] }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24"
                                    stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                    <path d="M9 15l6 -6" />
                                    <path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464" />
                                    <path
                                        d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463" />
                                </svg>
                                {{ $application->destination->server->ip }}:{{ explode(':', $port)[0] }}
                            </a>
                            @if (count($application->additional_servers) > 0)
                                @foreach ($application->additional_servers as $server)
                                    <a class="dropdown-item" target="_blank"
                                        href="http://{{ $server->ip }}:{{ explode(':', $port)[0] }}">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24"
                                            stroke-width="1.5" stroke="currentColor" fill="none"
                                            stroke-linecap="round" stroke-linejoin="round">
                                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                            <path d="M9 15l6 -6" />
                                            <path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464" />
                                            <path
                                                d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463" />
                                        </svg>
                                        {{ $server->ip }}:{{ explode(':', $port)[0] }}
                                    </a>
                                @endforeach
                            @endif
                        @endif
                    @endforeach
                @endif
            @else
                <div class="px-2 py-1.5 text-sm font-semibold">No links available</div>
            @endif
        </div>
    </div>
</div>
