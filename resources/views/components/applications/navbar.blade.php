<nav class="flex gap-4 py-2 border-b-2 border-solid border-coolgray-200">
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

    <div class="dropdown dropdown-bottom">
        <button tabindex="0"
            class="flex items-center justify-center h-full text-white normal-case bg-transparent border-none rounded btn btn-xs no-animation">
            Open
            <x-chevron-down />
        </button>
        <ul tabindex="0"
            class="text-xs text-white normal-case rounded min-w-max dropdown-content menu bg-coolgray-200">
            @if (data_get($application, 'fqdn'))
                <li>
                    <a class="text-xs hover:no-underline hover:bg-coollabs" target="_blank"
                        href="{{ $application->fqdn }}">
                        {{ $application->fqdn }}
                    </a>
                </li>
            @endif
            @if (data_get($application, 'ports_mappings_array'))
                @foreach ($application->ports_mappings_array as $port)
                    @if (config('app.env') === 'local')
                        <li>
                            <a class="text-xs hover:no-underline hover:bg-coollabs" target="_blank"
                                href="http://localhost:{{ explode(':', $port)[0] }}">Port
                                {{ explode(':', $port)[0] }}
                            </a>
                        </li>
                    @else
                        <li>
                            <a class="text-xs hover:no-underline hover:bg-coollabs" target="_blank"
                                href="http://{{ $application->destination->server->ip }}:{{ explode(':', $port)[0] }}">Port
                                {{ $port }}</a>
                        </li>
                    @endif
                @endforeach
            @endif
        </ul>
    </div>
    </div>
    <livewire:project.application.deploy :applicationId="$application->id" />
    <livewire:project.application.status :application="$application" />
</nav>
