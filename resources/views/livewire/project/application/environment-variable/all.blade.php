<div class="flex flex-col gap-2">
    <div>
        <h2>Environment Variables</h2>
        <div class="text-sm">Environment (secrets) variables for normal deployments.</div>
    </div>
    @foreach ($application->environment_variables as $env)
        <livewire:project.application.environment-variable.show wire:key="environment-{{ $env->id }}"
            :env="$env" />
    @endforeach
    <div class="pt-2 pb-8">
        <livewire:project.application.environment-varia /ble.add />
    </div>
    <div>
        <h3>Preview Deployments</h3>
        <div class="text-sm">Environment (secrets) variables for Preview Deployments.</div>
    </div>
    @foreach ($application->environment_variables_preview as $env)
        <livewire:project.application.environment-variable.show wire:key="environment-{{ $env->id }}"
            :env="$env" />
    @endforeach
    <div class="pt-2 pb-8">
        <livewire:project.application.environment-variable.add is_preview="true" />
    </div>
</div>
