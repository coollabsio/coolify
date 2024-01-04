<div tabindex="0" x-data="{ open: false }"
    class="transition border rounded cursor-pointer collapse collapse-arrow border-coolgray-200"
    :class="open ? 'collapse-open' : 'collapse-close'">
    <div class="flex flex-col justify-center text-sm select-text collapse-title" x-on:click="open = !open">
        <div>{{ $workdir }}{{ $fs_path }} -> {{ $fileStorage->mount_path }}</div>
    </div>
    <div class="collapse-content">
        <form wire:submit='submit' class="flex flex-col gap-2">
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
    </div>
</div>
