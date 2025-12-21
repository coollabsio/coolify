<form wire:submit="save" class="flex items-end gap-2">
    <x-forms.input helper="One domain per preview." label="Domains for {{ $serviceName }}" id="domain" canGate="update"
        :canResource="$preview->application"></x-forms.input>
    <x-forms.button type="submit">{{ __('common.save') }}</x-forms.button>
    <x-forms.button wire:click="generate">{{ __('common.generate_domain') }}</x-forms.button>
</form>
