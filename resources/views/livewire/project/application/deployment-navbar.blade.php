<div class="flex items-center gap-2 pb-4">
    <h2>Deployment Log</h2>
    @if (data_get($application_deployment_queue, 'status') === 'queued')
        <x-forms.button wire:click.prevent="force_start">Force Start</x-forms.button>
    @endif
    @if (
            data_get($application_deployment_queue, 'status') === 'in_progress' ||
            data_get($application_deployment_queue, 'status') === 'queued'
        )
        <x-forms.button isError wire:click.prevent="cancel">Cancel</x-forms.button>
    @endif
</div>