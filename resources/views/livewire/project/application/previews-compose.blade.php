<form wire:submit="save" class="flex items-end gap-2">
    <x-forms.input helper="One domain per preview." label="Domains for {{ str($serviceName)->headline() }}"
        id="service.domain"></x-forms.input>
    <x-forms.button type="submit">Save</x-forms.button>
    <x-forms.button wire:click="generate">Generate
        Domain</x-forms.button>
</form>
