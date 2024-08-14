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

            @if (!$fileStorage->is_based_on_git)
                <x-modal-confirmation isErrorButton buttonTitle="Delete">
                    <div class="px-2">This storage will be deleted. It is not reversible. <strong
                            class="text-error">Please
                            think
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
            @endif
        </div>
        @if (!$fileStorage->is_directory)
            @if (data_get($resource, 'settings.is_preserve_repository_enabled'))
                <div class="w-96">
                    <x-forms.checkbox instantSave label="Is this based on the Git repository?"
                        id="fileStorage.is_based_on_git"></x-forms.checkbox>
                </div>
            @endif
            <x-forms.textarea
                label="{{ $fileStorage->is_based_on_git ? 'Content (refreshed after a successful deployment)' : 'Content' }}"
                rows="20" id="fileStorage.content"
                readonly="{{ $fileStorage->is_based_on_git }}"></x-forms.textarea>
            @if (!$fileStorage->is_based_on_git)
                <x-forms.button class="w-full" type="submit">Save</x-forms.button>
            @endif
        @endif

    </form>

</div>
