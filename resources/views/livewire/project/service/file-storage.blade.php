<div>
    <div
        class="flex flex-col gap-4 rounded-lg bg-neutral-50 p-4 ring-1 ring-neutral-200 dark:bg-white/[0.025] dark:ring-white/[0.07]">
        @if ($fileStorage->is_too_large)
            <x-callout type="warning" title="File too large">
                File on server exceeds 5 MB and cannot be edited from the UI. Edit it directly on the server.
            </x-callout>
        @elseif ($fileStorage->is_host_file)
            <x-callout type="info" title="Host-managed file">
                This host file mount is bind-only. Coolify will not create, edit, load, chmod, or delete the source file.
            </x-callout>
        @elseif ($isReadOnly)
            <x-callout type="info" title="Read-only mount">
                @if ($fileStorage->is_directory)
                    This directory is mounted as read-only and cannot be modified from the UI.
                @else
                    This file is mounted as read-only and cannot be modified from the UI.
                @endif
            </x-callout>
        @endif
        <div class="flex flex-col justify-center text-sm select-text">
            <div class="grid gap-4 md:grid-cols-2">
                <x-forms.input label="Source Path" :value="$fileStorage->fs_path" readonly>
                    <x-slot:labelSuffix>
                        @if ($hasEnabledBackup)
                            <x-status-badge :as="$backupUrl ? 'a' : 'span'" :href="$backupUrl"
                                status="Backup enabled" type="success"
                                :class="$backupUrl ? 'cursor-pointer underline' : null" />
                        @endif
                    </x-slot:labelSuffix>
                </x-forms.input>
                <x-forms.input label="Destination Path" :value="$fileStorage->mount_path" readonly />
            </div>
        </div>
        @if ($resource instanceof \App\Models\Application)
            @can('update', $resource)
                <div class="w-full sm:w-96">
                    <x-forms.listbox id="isPreviewSuffixEnabled" label="PR deployment suffix"
                        helper="Choose whether preview deployments receive an isolated -pr-N path suffix."
                        onChange="instantSave" :options="[
                            ['value' => true, 'label' => 'Add suffix'],
                            ['value' => false, 'label' => 'Share path'],
                        ]" />
                </div>
            @endcan
        @endif
        <form wire:submit='submit' class="flex flex-col gap-4">
            <x-unsaved-bar action="submit" />
            @if (!$isReadOnly)
                @can('update', $resource)
                    <div class="flex gap-2">
                        @if ($fileStorage->is_host_file)
                            <x-modal-confirmation :ignoreWire="false" title="Confirm Host File Mount Removal?"
                                buttonTitle="Delete" isErrorButton submitAction="delete" :checkboxes="$hostFileDeletionCheckboxes"
                                :actions="['Only the mount configuration will be removed. The host file will not be deleted.']"
                                confirmationText="{{ $fs_path }}"
                                confirmationLabel="Please confirm the execution of the actions by entering the Filepath below"
                                shortConfirmationLabel="Filepath" />
                        @elseif ($fileStorage->is_directory)
                            <x-modal-confirmation :ignoreWire="false" title="Confirm Directory Conversion to File?"
                                buttonTitle="Convert to file" submitAction="convertToFile" :actions="[
                                    'All files in this directory will be permanently deleted and an empty file will be created in its place.',
                                ]"
                                confirmationText="{{ $fs_path }}"
                                confirmationLabel="Please confirm the execution of the actions by entering the Filepath below"
                                shortConfirmationLabel="Filepath" :confirmWithPassword="false" step2ButtonText="Convert to file" />
                            @if ($resource instanceof \App\Models\Application)
                                <x-modal-input buttonTitle="Configure Backup" title="Configure Directory Backup"
                                    :wireIgnore="false">
                                    <livewire:project.application.backup.create :application="$resource"
                                        :selected-target-key="'directory:' . $fileStorage->id"
                                        wire:key="configure-directory-backup-{{ $fileStorage->id }}" />
                                </x-modal-input>
                            @endif
                            <x-modal-confirmation :ignoreWire="false" title="Confirm Directory Deletion?" buttonTitle="Delete"
                                isErrorButton submitAction="delete" :checkboxes="$directoryDeletionCheckboxes" :actions="[
                                    'The selected directory and all its contents will be permanently deleted from the container.',
                                ]"
                                confirmationText="{{ $fs_path }}"
                                confirmationLabel="Please confirm the execution of the actions by entering the Filepath below"
                                shortConfirmationLabel="Filepath" />
                        @else
                            @if (!$fileStorage->is_binary && !$fileStorage->is_too_large)
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
                @if (!$fileStorage->is_directory && !$fileStorage->is_host_file)
                    @can('update', $resource)
                        @if (data_get($resource, 'settings.is_preserve_repository_enabled'))
                            <div class="w-full sm:w-96">
                                <x-forms.checkbox instantSave label="Is this based on the Git repository?"
                                    id="isBasedOnGit"></x-forms.checkbox>
                            </div>
                        @endif
                        <x-forms.textarea
                            label="{{ $fileStorage->is_based_on_git ? 'Content (refreshed after a successful deployment)' : 'Content' }}"
                            helper="The content shown may be outdated. Click 'Load from server' to fetch the latest version."
                            rows="20" id="content"
                            readonly="{{ $fileStorage->is_based_on_git || $fileStorage->is_binary || $fileStorage->is_too_large }}"></x-forms.textarea>
                    @else
                        @if (data_get($resource, 'settings.is_preserve_repository_enabled'))
                            <div class="w-full sm:w-96">
                                <x-forms.checkbox disabled label="Is this based on the Git repository?"
                                    id="isBasedOnGit"></x-forms.checkbox>
                            </div>
                        @endif
                        <x-forms.textarea
                            label="{{ $fileStorage->is_based_on_git ? 'Content (refreshed after a successful deployment)' : 'Content' }}"
                            helper="The content shown may be outdated. Click 'Load from server' to fetch the latest version."
                            rows="20" id="content" disabled></x-forms.textarea>
                    @endcan
                @endif
            @else
                {{-- Read-only view --}}
                @if (!$fileStorage->is_directory && !$fileStorage->is_host_file)
                    @can('update', $resource)
                        <div class="flex gap-2">
                            <x-forms.button type="button" wire:click="loadStorageOnServer">Load from
                                server</x-forms.button>
                        </div>
                    @endcan
                    @if (data_get($resource, 'settings.is_preserve_repository_enabled'))
                        <div class="w-full sm:w-96">
                            <x-forms.checkbox disabled label="Is this based on the Git repository?"
                                id="isBasedOnGit"></x-forms.checkbox>
                        </div>
                    @endif
                    <x-forms.textarea
                        label="{{ $fileStorage->is_based_on_git ? 'Content (refreshed after a successful deployment)' : 'Content' }}"
                        helper="The content shown may be outdated. Click 'Load from server' to fetch the latest version."
                        rows="20" id="content" disabled></x-forms.textarea>
                @endif
            @endif
        </form>
        @if ($isReadOnly && $fileStorage->is_directory && $resource instanceof \App\Models\Application)
            @can('update', $resource)
                <div>
                    <x-modal-input buttonTitle="Configure Backup" title="Configure Directory Backup" :wireIgnore="false">
                        <livewire:project.application.backup.create :application="$resource"
                            :selected-target-key="'directory:' . $fileStorage->id"
                            wire:key="configure-readonly-directory-backup-{{ $fileStorage->id }}" />
                    </x-modal-input>
                </div>
            @endcan
        @endif
    </div>
</div>
