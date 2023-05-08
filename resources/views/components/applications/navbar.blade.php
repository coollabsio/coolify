<nav class="flex gap-4 py-2">
    <a target="_blank" href="{{ $gitLocation }}">
        <x-inputs.button>Open on Git ↗️</x-inputs.button>
    </a>
    <a href="{{ route('project.application.configuration', Route::current()->parameters()) }}">
        <x-inputs.button>Configuration</x-inputs.button>
    </a>
    <a href="{{ route('project.application.deployments', Route::current()->parameters()) }}">
        <x-inputs.button>Deployments</x-inputs.button>
    </a>
    <livewire:project.application.deploy :applicationId="$applicationId" />
</nav>
