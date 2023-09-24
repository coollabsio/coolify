<form wire:submit.prevent='submit' class="flex flex-col gap-2">
    <div class="flex gap-2">
        <x-forms.input readonly label="File Path" id="fileStorage.fs_path"></x-forms.input>
        <x-forms.input readonly label="Mount Path (in container)" id="fileStorage.mount_path"></x-forms.input>
    </div>
    <x-forms.textarea label="Content" id="fileStorage.content"></x-forms.textarea>
    <x-forms.button type="submit">Save</x-forms.button>
</form>
