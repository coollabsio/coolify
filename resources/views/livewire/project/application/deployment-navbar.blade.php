<div class="flex items-center gap-2 pb-4">
    <h2>Logs</h2>
    @if (data_get($activity, 'properties.status') === 'in_progress')
        <x-forms.button wire:click.prevent="cancel">Cancel deployment</x-forms.button>
    @else
        <x-forms.button disabled>Cancel deployment</x-forms.button>
    @endif
</div>
