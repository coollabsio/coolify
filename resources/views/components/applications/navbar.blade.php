<nav class="flex justify-center gap-4 py-2 border-b-2 border-solid border-coolgray-200 ">
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
    <a target="_blank" href="{{ $application->gitBranchLocation }}">
        Open on Git <img class="inline-flex w-4 h-4" src="{{ asset('svgs/external-link.svg') }}">
    </a>
    @if (data_get($application, 'ports_mappings_array'))
        @foreach ($application->ports_mappings_array as $port)
            @if (config('app.env') === 'local')
                <a target="_blank" href="http://localhost:{{ explode(':', $port)[0] }}">Open
                    {{ explode(':', $port)[0] }} <img class="inline-flex w-4 h-4"
                        src="{{ asset('svgs/external-link.svg') }}"></a>
            @else
                <a target="_blank"
                    href="http://{{ $application->destination->server->ip }}:{{ explode(':', $port)[0] }}">Open
                    {{ $port }}</a>
            @endif
        @endforeach
    @endif
    <livewire:project.application.deploy :applicationId="$application->id" />
</nav>
