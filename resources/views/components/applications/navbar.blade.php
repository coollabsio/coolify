<nav class="flex gap-4 py-2">
    <a target="_blank" href="{{ $gitBranchLocation }}">
        <x-inputs.button>Open on Git ↗️</x-inputs.button>
    </a>
    <a
        href="{{ route('project.application.configuration', [
            'project_uuid' => Route::current()->parameters()['project_uuid'],
            'application_uuid' => Route::current()->parameters()['application_uuid'],
            'environment_name' => Route::current()->parameters()['environment_name'],
        ]) }}">
        <x-inputs.button>Configuration</x-inputs.button>
    </a>
    <a
        href="{{ route('project.application.deployments', [
            'project_uuid' => Route::current()->parameters()['project_uuid'],
            'application_uuid' => Route::current()->parameters()['application_uuid'],
            'environment_name' => Route::current()->parameters()['environment_name'],
        ]) }}">
        <x-inputs.button>Deployments</x-inputs.button>
    </a>
    <livewire:project.application.deploy :applicationId="$applicationId" />
</nav>
