<x-collapsible>
    <x-slot:title>
        <div>{{ $fileStorage->mount_path }}
            @empty($fileStorage->content)
                <span class="text-xs text-warning">(empty)</span>
            @endempty
        </div>
    </x-slot:title>
    <x-slot:action>
        <form wire:submit.prevent='submit' class="flex flex-col gap-2">
            <div class="flex gap-2">
                <x-forms.input readonly label="File in Docker Compose file" id="fileStorage.fs_path"></x-forms.input>
                <x-forms.input readonly label="File on Filesystem" id="fs_path"></x-forms.input>
            </div>
            <x-forms.input readonly label="Mount (in container)" id="fileStorage.mount_path"></x-forms.input>
            <x-forms.textarea label="Content" rows="20" id="fileStorage.content"></x-forms.textarea>
            <x-forms.button type="submit">Save</x-forms.button>
        </form>
    </x-slot:action>
</x-collapsible>
