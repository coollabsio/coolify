{{-- Main button of the build configuration. With coolify.json selected, it creates the resources from the file. --}}
@if ($this->usesConfigFile())
    @if ($configPlan && empty($configPlan['errors']))
        <x-forms.button type="button" wire:click="useConfigFile" isHighlighted :disabled="! $this->canLinkConfigFile()">
            Create resources
        </x-forms.button>
    @else
        <x-forms.button type="button" wire:click="previewConfigFile">Show plan</x-forms.button>
    @endif
@else
    <x-forms.button type="submit" wire:target="submit" isHighlighted>Continue</x-forms.button>
@endif
