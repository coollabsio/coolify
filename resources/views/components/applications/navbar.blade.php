<nav class="flex gap-4 py-2 border-b-2 border-solid border-coolgray-200">
    <a
        href="{{ route('project.application.configuration', [
            'project_uuid' => Route::current()->parameters()['project_uuid'],
            'application_uuid' => Route::current()->parameters()['application_uuid'],
            'environment_name' => Route::current()->parameters()['environment_name'],
        ]) }}">
        Configuration
    </a>
    <a
        href="{{ route('project.application.deployments', [
            'project_uuid' => Route::current()->parameters()['project_uuid'],
            'application_uuid' => Route::current()->parameters()['application_uuid'],
            'environment_name' => Route::current()->parameters()['environment_name'],
        ]) }}">
        Deployments
    </a>
    <div class="flex-1"></div>
    <div class="dropdown dropdown-hover">
        <x-inputs.button>Links
            <x-chevron-down />
        </x-inputs.button>
        <ul tabindex="0" class="p-2 font-bold text-white rounded min-w-max dropdown-content menu bg-coolgray-200">
            <li>
                <a class="text-xs" target="_blank" href="{{ $application->gitBranchLocation }}">
                    Open on Git
                    <x-external-link />
                </a>
            </li>
            @if (data_get($application, 'ports_mappings_array'))
                @foreach ($application->ports_mappings_array as $port)
                    @if (config('app.env') === 'local')
                        <li>
                            <a class="text-xs " target="_blank"
                                href="http://localhost:{{ explode(':', $port)[0] }}">Open
                                {{ explode(':', $port)[0] }}
                                <x-external-link />
                            </a>
                        </li>
                    @else
                        <li>
                            <a class="text-xs" target="_blank"
                                href="http://{{ $application->destination->server->ip }}:{{ explode(':', $port)[0] }}">Open
                                {{ $port }}</a>
                        </li>
                    @endif
                @endforeach
            @endif
        </ul>
    </div>
    <livewire:project.application.deploy :applicationId="$application->id" />
</nav>
