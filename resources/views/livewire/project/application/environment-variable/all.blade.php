<div class="flex flex-col gap-2">
    <h3>Environment Variables</h3>
    @forelse ($application->environment_variables as $env)
        <livewire:project.application.environment-variable.show wire:key="environment-{{ $env->id }}"
            :env="$env" />
    @empty
        <p>There are no environment variables added for this application.</p>
    @endforelse
    <div class="pt-10">
        <livewire:project.application.environment-variable.add />
    </div>
</div>
