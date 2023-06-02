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
    <livewire:project.application.status :application="$application" />
    <div class="flex-1"></div>
    <div class="dropdown dropdown-bottom">
        <label tabindex="0">
            <x-forms.button>
                Open
                <x-chevron-down />
            </x-forms.button>
        </label>
        <ul tabindex="0"
            class="mt-1 text-xs text-white normal-case rounded min-w-max dropdown-content menu bg-coolgray-200">
            @if (data_get($application, 'fqdn'))
                <li>
                    <a class="text-xs text-white rounded-none hover:no-underline hover:bg-coollabs" target="_blank"
                        href="{{ $application->fqdn }}">
                        {{ $application->fqdn }}
                        <x-external-link />
                    </a>
                </li>
            @endif
            @if (data_get($application, 'ports_mappings_array'))
                @foreach ($application->ports_mappings_array as $port)
                    @if (config('app.env') === 'local')
                        <li>
                            <a class="text-xs text-white rounded-none hover:no-underline hover:bg-coollabs"
                                target="_blank" href="http://localhost:{{ explode(':', $port)[0] }}">Port
                                {{ explode(':', $port)[0] }}
                                <x-external-link />
                            </a>
                        </li>
                    @else
                        <li>
                            <a class="text-xs hover:no-underline hover:bg-coollabs" target="_blank"
                                href="http://{{ $application->destination->server->ip }}:{{ explode(':', $port)[0] }}">Port
                                {{ $port }}
                                <x-external-link />
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
