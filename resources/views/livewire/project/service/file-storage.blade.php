<div class="p-4 transition border rounded cursor-pointer border-coolgray-200">
    <div class="flex flex-col justify-center pb-4 text-sm select-text">
        <h2>{{ data_get($resource, 'name', 'unknown') }}</h2>
        <div>{{ $workdir }}{{ $fs_path }} -> {{ $fileStorage->mount_path }}</div>
    </div>
    <div>
        <form wire:submit='submit' class="flex flex-col gap-2">
            <div class="w-64">
                <x-forms.checkbox instantSave label="Is directory?" id="fileStorage.is_directory"></x-forms.checkbox>
            </div>
            @if (!$fileStorage->is_directory)
                <x-forms.textarea label="Content" rows="20" id="fileStorage.content"></x-forms.textarea>
                <x-forms.button type="submit">Save</x-forms.button>
            @endif
        </form>
    </div>
</div>
