<form wire:submit="saveResources" class="flex flex-col gap-6">
    @can('update', $workload)
        <x-unsaved-bar action="saveResources"
            targets="cpuLimit,cpuReservation,memoryLimitMb,memoryReservationMb" />
    @endcan

    <x-application.settings-section id="cluster-application-cpu-limits" title="CPU"
        helper="Optional runtime limit and scheduling reservation. Empty values mean unlimited. Redeploy the application to apply changes.">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-forms.input id="cpuLimit" type="number" min="0.01" max="1024" step="0.01"
                label="CPU limit (cores)" canGate="update" :canResource="$workload"
                helper="Maximum CPU capacity that Podman can use." />
            <x-forms.input id="cpuReservation" type="number" min="0.01" max="1024" step="0.01"
                label="CPU reservation (cores)" canGate="update" :canResource="$workload"
                helper="Capacity reserved for placement. Podman uses it as relative CPU weight." />
        </div>
    </x-application.settings-section>

    <x-application.settings-section id="cluster-application-memory-limits" title="Memory"
        helper="Optional runtime limit and scheduling reservation. Empty values mean unlimited. Redeploy the application to apply changes.">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-forms.input id="memoryLimitMb" type="number" min="4" max="1048576"
                label="Memory limit (MiB)" canGate="update" :canResource="$workload"
                helper="Maximum memory available to the container." />
            <x-forms.input id="memoryReservationMb" type="number" min="4" max="1048576"
                label="Memory reservation (MiB)" canGate="update" :canResource="$workload"
                helper="Capacity reserved for placement and soft runtime memory pressure." />
        </div>
    </x-application.settings-section>
</form>
