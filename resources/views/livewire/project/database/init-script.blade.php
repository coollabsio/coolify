<form wire:submit="submit">
    <div class="flex items-end gap-2">
        <x-forms.input id="filename" label="Filename" />
        <x-forms.button type="submit">Save</x-forms.button>
        <x-modal-confirmation isErrorButton buttonTitle="Delete">
            This script will be deleted. It is not reversible. <br>Please think again.
        </x-modal-confirmation>
    </div>
    <x-forms.textarea id="content" label="Content" />
</form>
