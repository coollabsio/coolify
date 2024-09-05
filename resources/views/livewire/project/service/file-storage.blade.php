<div class="py-4 ">
    <div class="flex flex-col justify-center pb-4 text-sm select-text">
        {{-- @if (data_get($resource, 'build_pack') === 'dockercompose')
            <h4>{{ data_get($resource, 'name', 'unknown') }}</h4>
        @endif --}}
        @if ($fileStorage->is_directory)
            <h4 class="dark:text-white pt-4 border-t dark:border-coolgray-200">Directory Mount</h4>
        @else
            <h4 class="dark:text-white pt-4 border-t dark:border-coolgray-200">File Mount</h4>
        @endif

        <x-forms.input label="Source Path" :value="$fileStorage->fs_path" readonly />
        <x-forms.input label="Destination Path" :value="$fileStorage->mount_path" readonly />
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
