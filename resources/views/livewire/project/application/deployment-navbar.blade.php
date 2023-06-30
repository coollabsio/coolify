<div class="flex items-center gap-2 pb-4">
    <h2>Logs</h2>
    <x-forms.button wire:click.prevent="show_debug">Show Debug Logs</x-forms.button>
    @if (data_get($application_deployment_queue, 'status') === 'in_progress' ||
            data_get($application_deployment_queue, 'status') === 'queued')
        <x-forms.button wire:click.prevent="cancel">Cancel deployment</x-forms.button>
    @endif
</div>
