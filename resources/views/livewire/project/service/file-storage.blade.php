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
                <x-modal-confirmation
                title="Confirm Directory Conversion to File?"
                buttonTitle="Convert to file"
                submitAction="convertToFile"
                :actions="['All files in this directory will be permanently deleted and an empty file will be created in its place.']"
                confirmationText="{{ $fs_path }}"
                confirmationLabel="Please confirm the execution of the actions by entering the Filepath below"
                shortConfirmationLabel="Filepath"
                :confirmWithPassword="false"
                step2ButtonText="Convert to file"
                />
            @else
                <x-modal-confirmation 
                title="Confirm File Conversion to Directory?"
                buttonTitle="Convert to directory"
                submitAction="convertToDirectory"
                :actions="['The selected file will be permanently deleted and an empty directory will be created in its place.']"
                confirmationText="{{ $fs_path }}"
                confirmationLabel="Please confirm the execution of the actions by entering the Filepath below"
                shortConfirmationLabel="Filepath"
                :confirmWithPassword="false"
                step2ButtonText="Convert to directory"
                />
            @endif
            @if ($fileStorage->is_directory)
                <x-modal-confirmation 
                    title="Confirm Directory Deletion?"
                    buttonTitle="Delete Directory"
                    isErrorButton
                    submitAction="delete"
                    :checkboxes="$directoryDeletionCheckboxes" 
                    :actions="['The selected directory and all its contents will be permanently deleted from the container.']"
                    confirmationText="{{ $fs_path }}"
                    confirmationLabel="Please confirm the execution of the actions by entering the Filepath below"
                    shortConfirmationLabel="Filepath"
                    step3ButtonText="Permanently Delete Directory"
                />
            @else
                <x-modal-confirmation 
                    title="Confirm File Deletion?"
                    buttonTitle="Delete File"
                    isErrorButton
                    submitAction="delete"
                    :checkboxes="$fileDeletionCheckboxes" 
                    :actions="['The selected file will be permanently deleted from the container.']"
                    confirmationText="{{ $fs_path }}"
                    confirmationLabel="Please confirm the execution of the actions by entering the Filepath below"
                    shortConfirmationLabel="Filepath"
                    step3ButtonText="Permanently Delete File"
                />
            @endif
        </div>
        @if (!$fileStorage->is_directory)
            <x-forms.textarea label="Content" rows="20" id="fileStorage.content"></x-forms.textarea>
            <x-forms.button class="w-full" type="submit">Save</x-forms.button>
        @endif

    </form>
</div>
