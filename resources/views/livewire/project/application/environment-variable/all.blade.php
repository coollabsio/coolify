<div class="flex flex-col gap-2">
    <h3>Environment Variables</h3>
    @forelse ($application->environment_variables as $env)
        <livewire:project.application.environment-variable.show wire:key="environment-{{ $env->id }}"
            :env="$env" />
    @empty
        <p>There are no environment variables for this application.</p>
    @endforelse
    <h4>Add new environment variable</h4>
    <livewire:project.application.environment-variable.add />
</div>
