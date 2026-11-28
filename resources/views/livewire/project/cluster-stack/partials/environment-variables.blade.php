{{-- One block for each service: a service of a stack has its own environment variables (decision 0023). --}}
<div class="flex flex-col gap-10">
    @foreach ($workloads as $workload)
        <div id="environment-variables-{{ $workload->stack_service }}" wire:key="cluster-stack-envs-{{ $workload->uuid }}" class="flex flex-col gap-3">
            <h3 class="text-sm font-semibold text-black dark:text-fg">{{ str($workload->stack_service)->headline() }}</h3>
            <livewire:project.shared.environment-variable.all :resource="$workload" wire:key="cluster-stack-envs-all-{{ $workload->uuid }}" />
        </div>
    @endforeach
</div>
