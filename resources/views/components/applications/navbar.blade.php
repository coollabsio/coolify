<nav class="flex gap-4 py-2 bg-gray-100">
    <a href="{{ route('project.application.configuration', Route::current()->parameters()) }}">Configuration</a>
    <a href="{{ route('project.application.deployments', Route::current()->parameters()) }}">Deployments</a>
    <livewire:project.application.deploy :applicationId="$applicationId" />
</nav>
