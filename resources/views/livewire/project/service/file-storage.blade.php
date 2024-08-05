<div class="p-4 transition border rounded dark:border-coolgray-200">
    <div class="flex flex-col justify-center pb-4 text-sm select-text">
        @if (data_get($resource, 'build_pack') === 'dockercompose')
            <h2>{{ data_get($resource, 'name', 'unknown') }}</h2>
        @endif
        @if ($fileStorage->is_directory)
            <div class="dark:text-white">Directory Mount</div>
        @else
            <div class="dark:text-white">File Mount</div>
        @endif
        <div>{{ $workdir }}{{ $fs_path }} -> {{ $fileStorage->mount_path }}</div>
    </div>
    <form wire:submit='submit' class="flex flex-col gap-2">
        <div class="flex gap-2">
            @if ($fileStorage->is_directory)
                <x-modal-confirmation action="convertToFile" buttonTitle="Convert to file">
                    <div>This will delete all files in this directory. It is not reversible. <strong
                            class="text-error">Please think
                            again.</strong><br><br></div>
                </x-modal-confirmation>
            @else
                <x-modal-confirmation action="convertToDirectory" buttonTitle="Convert to directory">
                    <div>This will delete the file and make a directory instead. It is not reversible.
                        <strong class="text-error">Please think
                            again.</strong><br><br>
                    </div>
                </x-modal-confirmation>
            @endif
            <x-modal-confirmation isErrorButton buttonTitle="Delete">
                <div class="px-2">This resource will be deleted. It is not reversible. <strong
                        class="text-error">Please think
                        again.</strong><br><br></div>
                <h4>Actions</h4>
                @if ($fileStorage->is_directory)
                    <x-forms.checkbox id="permanently_delete"
                        label="Permanently delete directory from the server?"></x-forms.checkbox>
                @else
                    <x-forms.checkbox id="permanently_delete"
                        label="Permanently delete file from the server?"></x-forms.checkbox>
                @endif
            </x-modal-confirmation>
        </div>
        @if (!$fileStorage->is_directory)
            <x-forms.textarea label="Content" rows="20" id="fileStorage.content"></x-forms.textarea>
            <x-forms.button class="w-full" type="submit">Save</x-forms.button>
        @endif

    </form>
</div>
