<div class="flex items-center gap-2 pb-4">
    <h2>{{ __('deployment.deployment_log') }}</h2>
    @if (data_get($application_deployment_queue, 'status') === 'queued')
        <x-forms.button wire:click.prevent="force_start">{{ __('deployment.force_start') }}</x-forms.button>
    @endif
    @if (
            data_get($application_deployment_queue, 'status') === 'in_progress' ||
            data_get($application_deployment_queue, 'status') === 'queued'
        )
        <x-forms.button isError wire:click.prevent="cancel">{{ __('common.cancel') }}</x-forms.button>
    @endif
</div>