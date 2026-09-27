<x-application.settings-section id="cluster-application-environment-variables" title="Environment variables"
    helper="Available to the container at runtime. Changes create a new revision. Redeploy the application to apply them.">
    @can('update', $workload)
        <form wire:submit="saveConfiguration" class="flex flex-col gap-4">
            <x-unsaved-bar action="saveConfiguration" targets="environmentVariables" />
            <x-forms.textarea id="environmentVariables" label="Variables" rows="12"
                class="whitespace-pre-wrap font-mono" placeholder="KEY=value"
                helper="One KEY=VALUE pair per line. Lines that start with # are ignored. Values are stored encrypted." />
        </form>
    @else
        <x-empty size="sm" title="Environment variables are hidden"
            description="You need permission to update this application to view or change its environment variables."
            icon-name="variables" />
    @endcan
</x-application.settings-section>
