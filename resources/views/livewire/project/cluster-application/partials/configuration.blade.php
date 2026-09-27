<form wire:submit="saveConfiguration" class="flex flex-col gap-6">
    @can('update', $workload)
        <x-unsaved-bar action="saveConfiguration" targets="startCommand" />
    @endcan

    <x-application.settings-section id="cluster-application-runtime" title="Runtime"
        helper="Changes create a new revision. Redeploy the application to apply them.">
        <x-forms.input id="startCommand" label="Start command" placeholder="nginx -g &quot;daemon off;&quot;"
            canGate="update" :canResource="$workload"
            helper="Overrides the image command. Use double quotes for arguments with spaces. Leave empty to use the image default." />
    </x-application.settings-section>
</form>
