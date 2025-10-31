<div>
    <div class="flex flex-col gap-4 p-4 bg-white border dark:bg-base dark:border-coolgray-300 border-neutral-200">
        @if ($isReadOnly)
            <div class="w-full p-2 text-sm rounded bg-warning/10 text-warning">
                @if ($fileStorage->is_directory)
                    This directory is mounted as read-only and cannot be modified from the UI.
                @else
                    This file is mounted as read-only and cannot be modified from the UI.
                @endif
            </div>
        @endif
        <div class="flex flex-col justify-center text-sm select-text">
            <div class="flex gap-2  md:flex-row flex-col">
                <x-forms.input label="Source Path" :value="$fileStorage->fs_path" readonly />
                <x-forms.input label="Destination Path" :value="$fileStorage->mount_path" readonly />
            </div>
        </div>
        <form wire:submit='submit' class="flex flex-col gap-2">
            @if (!$isReadOnly)
                @can('update', $resource)
                    <div class="flex gap-2">
                        @if ($fileStorage->is_directory)
                            <x-modal-confirmation :ignoreWire="false" title="Confirm Directory Conversion to File?"
                                buttonTitle="Convert to file" submitAction="convertToFile" :actions="[
                                    'All files in this directory will be permanently deleted and an empty file will be created in its place.',
                                ]"
                                confirmationText="{{ $fs_path }}"
                                confirmationLabel="Please confirm the execution of the actions by entering the Filepath below"
                                shortConfirmationLabel="Filepath" :confirmWithPassword="false" step2ButtonText="Convert to file" />
                            <x-modal-confirmation :ignoreWire="false" title="Confirm Directory Deletion?" buttonTitle="Delete"
                                isErrorButton submitAction="delete" :checkboxes="$directoryDeletionCheckboxes" :actions="[
                                    'The selected directory and all its contents will be permanently deleted from the container.',
                                ]"
                                confirmationText="{{ $fs_path }}"
                                confirmationLabel="Please confirm the execution of the actions by entering the Filepath below"
                                shortConfirmationLabel="Filepath" />
                        @else
                            @if (!$fileStorage->is_binary)
                                <x-modal-confirmation :ignoreWire="false" title="Confirm File Conversion to Directory?"
                                    buttonTitle="Convert to directory" submitAction="convertToDirectory" :actions="[
                                        'The selected file will be permanently deleted and an empty directory will be created in its place.',
                                    ]"
                                    confirmationText="{{ $fs_path }}"
                                    confirmationLabel="Please confirm the execution of the actions by entering the Filepath below"
                                    shortConfirmationLabel="Filepath" :confirmWithPassword="false"
                                    step2ButtonText="Convert to directory" />
                            @endif
                            <x-forms.button type="button" wire:click="loadStorageOnServer">Load from
                                server</x-forms.button>
                            <x-modal-confirmation :ignoreWire="false" title="Confirm File Deletion?" buttonTitle="Delete"
                                isErrorButton submitAction="delete" :checkboxes="$fileDeletionCheckboxes" :actions="['The selected file will be permanently deleted from the container.']"
                                confirmationText="{{ $fs_path }}"
                                confirmationLabel="Please confirm the execution of the actions by entering the Filepath below"
                                shortConfirmationLabel="Filepath" />
                        @endif
                    </div>
                @endcan
                @if (!$fileStorage->is_directory)
                    @can('update', $resource)
                        @if (data_get($resource, 'settings.is_preserve_repository_enabled'))
                            <div class="w-96">
                                <x-forms.checkbox instantSave label="Is this based on the Git repository?"
                                    id="isBasedOnGit"></x-forms.checkbox>
                            </div>
                        @endif
                        <x-forms.textarea
                            label="{{ $fileStorage->is_based_on_git ? 'Content (refreshed after a successful deployment)' : 'Content' }}"
                            rows="20" id="content"
                            readonly="{{ $fileStorage->is_based_on_git || $fileStorage->is_binary }}"></x-forms.textarea>
                        @if (!$fileStorage->is_based_on_git && !$fileStorage->is_binary)
                            <x-forms.button class="w-full" type="submit">Save</x-forms.button>
                        @endif
                    @else
                        @if (data_get($resource, 'settings.is_preserve_repository_enabled'))
                            <div class="w-96">
                                <x-forms.checkbox disabled label="Is this based on the Git repository?"
                                    id="isBasedOnGit"></x-forms.checkbox>
                            </div>
                        @endif
                        <x-forms.textarea
                            label="{{ $fileStorage->is_based_on_git ? 'Content (refreshed after a successful deployment)' : 'Content' }}"
                            rows="20" id="content" disabled></x-forms.textarea>
                    @endcan
                @endif
            @else
                {{-- Read-only view --}}
                @if (!$fileStorage->is_directory)
                    @if (data_get($resource, 'settings.is_preserve_repository_enabled'))
                        <div class="w-96">
                            <x-forms.checkbox disabled label="Is this based on the Git repository?"
                                id="isBasedOnGit"></x-forms.checkbox>
                        </div>
                    @endif
                    <x-forms.textarea
                        label="{{ $fileStorage->is_based_on_git ? 'Content (refreshed after a successful deployment)' : 'Content' }}"
                        rows="20" id="content" disabled></x-forms.textarea>
                @endif
            @endif
        </form>
    </div>
</div>
