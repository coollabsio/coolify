<div class="py-4">
    @if (data_get($activity, 'properties.status') === 'in_progress')
        <x-forms.button wire:click.prevent="cancel">Cancel deployment</x-forms.button>
    @else
        <x-forms.button disabled>Cancel deployment</x-forms.button>
    @endif
</div>
