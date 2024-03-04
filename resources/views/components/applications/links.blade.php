<div class="group">
    @if (
        (data_get($application, 'fqdn') ||
            collect(json_decode($this->application->docker_compose_domains))->count() > 0 ||
            data_get($application, 'previews', collect([]))->count() > 0 ||
            data_get($application, 'ports_mappings_array')) &&
            data_get($application, 'settings.is_raw_compose_deployment_enabled') !== true)
        <label tabindex="0" class="flex items-center gap-2 cursor-pointer hover:text-white"> Open Application
            <x-chevron-down />
        </label>

        <div class="absolute z-50 hidden group-hover:block">
            <ul tabindex="0"
                class="relative -ml-24 text-xs text-white normal-case rounded min-w-max menu bg-coolgray-200">
                @if (data_get($application, 'gitBrancLocation'))
                    <li>
                        <a target="_blank"
                            class="text-xs text-white rounded-none hover:no-underline hover:bg-coollabs hover:text-white"
                            href="{{ $application->gitBranchLocation }}">
                            <x-git-icon git="{{ $application->source?->getMorphClass() }}" />
                            Git Repository
                        </a>
                    </li>
                @endif
                @if (data_get($application, 'build_pack') === 'dockercompose')
                    @foreach (collect(json_decode($this->application->docker_compose_domains)) as $fqdn)
                        @if (data_get($fqdn, 'domain'))
                            @foreach (explode(',', data_get($fqdn, 'domain')) as $domain)
                                <li>
                                    <a class="text-xs text-white rounded-none hover:no-underline hover:bg-coollabs hover:text-white"
                                        target="_blank" href="{{ getFqdnWithoutPort($domain) }}">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24"
                                            stroke-width="1.5" stroke="currentColor" fill="none"
                                            stroke-linecap="round" stroke-linejoin="round">
                                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                            <path d="M9 15l6 -6" />
                                            <path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464" />
                                            <path
                                                d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463" />
                                        </svg>{{ getFqdnWithoutPort($domain) }}
                                    </a>
                                </li>
                            @endforeach
                        @endif
                    @endforeach
                @endif
                @if (data_get($application, 'fqdn'))
                    @foreach (str(data_get($application, 'fqdn'))->explode(',') as $fqdn)
                        <li>
                            <a class="text-xs text-white rounded-none hover:no-underline hover:bg-coollabs hover:text-white"
                                target="_blank" href="{{ getFqdnWithoutPort($fqdn) }}">
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
                        </li>
                    @endforeach
                @endif
                @if (data_get($application, 'previews', collect([]))->count() > 0)
                    @foreach (data_get($application, 'previews') as $preview)
                        @if (data_get($preview, 'fqdn'))
                            <li>
                                <a class="text-xs text-white rounded-none hover:no-underline hover:bg-coollabs hover:text-white"
                                    target="_blank" href="{{ getFqdnWithoutPort(data_get($preview, 'fqdn')) }}">
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
                            </li>
                        @endif
                    @endforeach
                @endif
                @if (data_get($application, 'ports_mappings_array'))
                    @foreach ($application->ports_mappings_array as $port)
                        @if ($application->destination->server->id === 0)
                            <li>
                                <a class="text-xs text-white rounded-none hover:no-underline hover:bg-coollabs hover:text-white"
                                    target="_blank" href="http://localhost:{{ explode(':', $port)[0] }}">
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
                            </li>
                        @else
                            <li>
                                <a class="text-xs text-white rounded-none hover:no-underline hover:bg-coollabs hover:text-white"
                                    target="_blank"
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
                            </li>
                            @if (count($application->additional_servers) > 0)
                                @foreach ($application->additional_servers as $server)
                                    <li>
                                        <a class="text-xs text-white rounded-none hover:no-underline hover:bg-coollabs hover:text-white"
                                            target="_blank"
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
                                    </li>
                                @endforeach
                            @endif
                        @endif
                    @endforeach
                @endif
            </ul>
        </div>
    @endif
</div>
