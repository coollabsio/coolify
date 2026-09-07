<div @class([
    'logs-viewer-deployment-actions',
    'hidden' => ! in_array(data_get($application_deployment_queue, 'status'), ['queued', 'in_progress'], true),
])>
    @if (
        data_get($application_deployment_queue, 'status') === 'queued'
            || data_get($application_deployment_queue, 'status') === 'in_progress')
        <div class="flex flex-wrap items-center justify-end gap-2">
            @if (data_get($application_deployment_queue, 'status') === 'queued')
                <x-forms.button class="logs-viewer-deployment-btn" wire:click.prevent="force_start">Force start</x-forms.button>
            @endif
            <x-forms.button isError class="logs-viewer-deployment-btn logs-viewer-cancel-btn" wire:click.prevent="cancel">Cancel deployment</x-forms.button>
        </div>
    @endif
</div>
