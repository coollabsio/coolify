<nav class="flex gap-4 py-2">
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
    <a target="_blank" href="{{ $gitBranchLocation }}">
        Open on Git ↗️
    </a>
    <livewire:project.application.deploy :applicationId="$applicationId" />
</nav>
