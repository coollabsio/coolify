<div class="flex flex-col gap-2">
    <h3>Environment Variables</h3>
    @foreach ($application->environment_variables as $env)
        <livewire:project.application.environment-variable.show wire:key="environment-{{ $env->id }}"
            :env="$env" />
    @endforeach
    <div class="pt-10">
        <livewire:project.application.environment-variable.add />
    </div>
</div>
