<nav class="flex pt-2 pb-10">
    <ol class="flex items-center">
        <li class="inline-flex items-center">
            <a class="text-xs truncate lg:text-sm"
                href="{{ route('project.show', ['project_uuid' => request()->route('project_uuid')]) }}">
                {{ $application->environment->project->name }}</a>
        </li>
        <li>
            <div class="flex items-center">
                <svg aria-hidden="true" class="w-4 h-4 mx-1 font-bold text-warning" fill="currentColor" viewBox="0 0 20 20"
                    xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd"
                        d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                        clip-rule="evenodd"></path>
                </svg>
                <a class="text-xs truncate lg:text-sm"
                    href="{{ route('project.resources', ['environment_name' => request()->route('environment_name'), 'project_uuid' => request()->route('project_uuid')]) }}">{{ request()->route('environment_name') }}</a>
            </div>
        </li>
        <li>
            <div class="flex items-center">
                <svg aria-hidden="true" class="w-4 h-4 mx-1 font-bold text-warning" fill="currentColor"
                    viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd"
                        d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                        clip-rule="evenodd"></path>
                </svg>
                <span class="text-xs truncate lg:text-sm">{{ data_get($application, 'name') }}</span>
            </div>
        </li>
        <li>
            <div class="flex items-center">
                <svg aria-hidden="true" class="w-4 h-4 mx-1 font-bold text-warning" fill="currentColor"
                    viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd"
                        d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                        clip-rule="evenodd"></path>
                </svg>
                <livewire:project.application.status :application="$application" />
            </div>
        </li>
    </ol>
</nav>
<nav class="flex items-end gap-4 py-2 border-b-2 border-solid border-coolgray-200">
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
        <label tabindex="0" class="flex items-center gap-2 cursor-pointer hover:text-white"> Links
            <x-chevron-down />
        </label>
        <div class="absolute hidden group-hover:block">
            <ul tabindex="0"
                class="relative -ml-24 text-xs text-white normal-case rounded min-w-max menu bg-coolgray-200">
                <li>
                    <a target="_blank" class="text-xs text-white rounded-none hover:no-underline"
                        href="{{ $application->gitBranchLocation }}">
                        <x-git-icon git="{{ $application->source?->getMorphClass() }}" />
                        Git Repository
                    </a>
                </li>
                @if (data_get($application, 'fqdn'))
                    @foreach (Str::of(data_get($application, 'fqdn'))->explode(',') as $fqdn)
                        <li>
                            <a class="text-xs text-white rounded-none hover:no-underline hover:bg-coollabs"
                                target="_blank" href="{{ $fqdn }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24"
                                    stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                    <path d="M9 15l6 -6" />
                                    <path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464" />
                                    <path
                                        d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463" />
                                </svg>{{ $fqdn }}
                            </a>
                        </li>
                    @endforeach
                @endif
                @if (data_get($application, 'ports_mappings_array'))
                    @foreach ($application->ports_mappings_array as $port)
                        @if (isDev())
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
