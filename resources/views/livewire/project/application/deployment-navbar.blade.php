<div>
    @if (
        data_get($application_deployment_queue, 'status') === 'queued'
            || data_get($application_deployment_queue, 'status') === 'in_progress')
        <div class="flex justify-end gap-2">
            @if (data_get($application_deployment_queue, 'status') === 'queued')
                <x-forms.button wire:click.prevent="force_start">Force start</x-forms.button>
            @endif
            <x-forms.button isError wire:click.prevent="cancel">Cancel deployment</x-forms.button>
        </div>
    @endif
</div>
