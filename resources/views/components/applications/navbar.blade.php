<nav class="flex items-center gap-4 py-2 border-b-2 border-solid border-coolgray-200">
    <a class="{{ request()->routeIs('project.application.configuration') ? 'text-white' : '' }}"
        href="{{ route('project.application.configuration', [
            'project_uuid' => Route::current()->parameters()['project_uuid'],
            'application_uuid' => Route::current()->parameters()['application_uuid'],
            'environment_name' => Route::current()->parameters()['environment_name'],
        ]) }}">
        <button>Configuration</button>
    </a>
    <a class="{{ request()->routeIs('project.application.deployments') ? 'text-white' : '' }}"
        href="{{ route('project.application.deployments', [
            'project_uuid' => Route::current()->parameters()['project_uuid'],
            'application_uuid' => Route::current()->parameters()['application_uuid'],
            'environment_name' => Route::current()->parameters()['environment_name'],
        ]) }}">
        <button>Deployments</button>
    </a>
    <div class="flex-1"></div>
    <div class="group">
        <label tabindex="0" class="flex items-center gap-2 text-sm cursor-pointer hover:text-white"> Links
            <x-chevron-down />
        </label>
        <div class="absolute hidden group-hover:block ">
            <ul tabindex="0" class="text-xs text-white normal-case rounded min-w-max menu bg-coolgray-200">
                <li>
                    <a target="_blank" class="text-xs text-white rounded-none hover:no-underline hover:bg-coollabs"
                        href="{{ $application->gitBranchLocation }}">
                        <x-git-icon git="{{ $application->source->getMorphClass() }}" />
                        Git Repository
                    </a>
                </li>
                @if (data_get($application, 'fqdn'))
                    <li>
                        <a class="text-xs text-white rounded-none hover:no-underline hover:bg-coollabs" target="_blank"
                            href="{{ $application->fqdn }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path d="M9 15l6 -6" />
                                <path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464" />
                                <path
                                    d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463" />
                            </svg>{{ $application->fqdn }}
                        </a>
                    </li>
                @endif
                @if (data_get($application, 'ports_mappings_array'))
                    @foreach ($application->ports_mappings_array as $port)
                        @if (config('app.env') === 'local')
                            <li>
                                <a class="text-xs text-white rounded-none hover:no-underline hover:bg-coollabs"
                                    target="_blank" href="http://localhost:{{ explode(':', $port)[0] }}">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24"
                                        stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                        <path d="M9 15l6 -6" />
                                        <path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464" />
                                        <path
                                            d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463" />
                                    </svg>{{ $port }}
                                </a>
                            </li>
                        @else
                            <li>
                                <a class="text-xs hover:no-underline hover:bg-coollabs" target="_blank"
                                    href="http://{{ $application->destination->server->ip }}:{{ explode(':', $port)[0] }}">Port
                                    {{ $port }}
                                </a>
                            </li>
                        @endif
                    @endforeach
                @endif
            </ul>
        </div>
    </div>
    <livewire:project.application.deploy :applicationId="$application->id" />
</nav>
