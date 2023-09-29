<x-collapsible>
    <x-slot:title>
        <div>{{ $fileStorage->fs_path }} -> {{ $fileStorage->mount_path }}</div>
    </x-slot:title>
    <x-slot:action>
        <form wire:submit.prevent='submit' class="flex flex-col gap-2">
            <div class="w-64">
                <x-forms.checkbox instantSave label="Is directory?" id="fileStorage.is_directory"></x-forms.checkbox>
            </div>
            {{-- @if ($fileStorage->is_directory)
                <x-forms.input readonly label="Directory on Filesystem (save files here)" id="fs_path"></x-forms.input>
            @else --}}
            {{-- <div class="flex gap-2">
                    <x-forms.input readonly label="File in Docker Compose file" id="fileStorage.fs_path"></x-forms.input>
                    <x-forms.input readonly label="File on Filesystem (save files here)" id="fs_path"></x-forms.input>
                </div>
                <x-forms.input readonly label="Mount (in container)" id="fileStorage.mount_path"></x-forms.input> --}}
            @if (!$fileStorage->is_directory)
                <x-forms.textarea label="Content" rows="20" id="fileStorage.content"></x-forms.textarea>
                <x-forms.button type="submit">Save</x-forms.button>
            @endif
            {{-- @endif --}}
        </form>
    </x-slot:action>
</x-collapsible>
