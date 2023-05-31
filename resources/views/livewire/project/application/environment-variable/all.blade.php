<div class="flex flex-col gap-2">
    <div>
        <h2 class="pb-0">Environment Variables</h2>
        <div class="text-sm">Environment (secrets) configuration. You can set variables for your Preview Deployments as
            well
            here.</div>
    </div>
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
