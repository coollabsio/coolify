<form class="flex flex-col w-full gap-2" wire:submit='submit'>
    <x-forms.input id="name" label="{{ __('common.name') }}" required />
    <x-forms.input id="description" label="{{ __('common.description') }}" />
    <x-forms.button type="submit">
        Continue
    </x-forms.button>
</form>
