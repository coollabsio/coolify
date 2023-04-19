<nav class="flex gap-4 py-2 bg-gray-100">
    <a href="{{ route('project.applications.configuration', Route::current()->parameters()) }}">Configuration</a>
    <a href="{{ route('project.applications.deployments', Route::current()->parameters()) }}">Deployments</a>
    <livewire:deploy-application :applicationId="$applicationId" />
</nav>
