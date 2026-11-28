@php
    $kind = $fileStorage->is_directory ? 'directory' : 'file';
    $hasContent = !$fileStorage->is_directory && !$fileStorage->is_host_file;
    $hasPreviewSuffix = $resource instanceof \App\Models\Application && $resource->git_based() && filled($resource->git_repository);
    $canEditSettings = !$isDetachedFromCompose && $hasContent;
    $canConfigureBackup = $fileStorage->is_directory && !$isDetachedFromCompose && $resource instanceof \App\Models\Application;
    $canDelete = $isDetachedFromCompose || !$isReadOnly;
    $confirmationLabel = 'Please confirm the execution of the actions by entering the Filepath below';
    $displaySourcePath = $fileStorage->fs_path;
    foreach (rescue(fn () => $fileStorage->contentBaseDirectories(), [], false) as $directory) {
        if (str_starts_with($fileStorage->fs_path, rtrim($directory, '/') . '/')) {
            $displaySourcePath = '.' . substr($fileStorage->fs_path, strlen(rtrim($directory, '/')));
            break;
        }
    }
    $hasStatus = $isDetachedFromCompose || $fileStorage->is_host_file || $fileStorage->is_too_large || $isReadOnly
        || $fileStorage->is_based_on_git || $hasEnabledBackup || filled($fileStorage->variable_fs_path);
@endphp

<div class="env-table-item">
    <x-modal-input :title="$fileStorage->mount_path" :subtitle="$fileStorage->fs_path" :wireIgnore="false"
        :closeEvent="false" :contentClicks="$canEditSettings" isLarge>
        <x-slot:content>
            <div @class([
                'data-table-row text-[13px] text-neutral-700 dark:text-fg-dim',
                $hasPreviewSuffix ? 'file-mounts-table-grid-with-pr' : 'file-mounts-table-grid',
                'cursor-pointer' => $canEditSettings,
            ])>
                <div class="min-w-0">
                    <span class="volumes-mobile-label volumes-field-label">Source Path</span>
                    <span class="block min-w-0 truncate text-[13px] font-medium text-neutral-950 dark:text-fg"
                        title="{{ $fileStorage->fs_path }}">{{ $displaySourcePath }}</span>
                </div>
                <div class="min-w-0">
                    <span class="volumes-mobile-label volumes-field-label">Destination Path</span>
                    <span class="block min-w-0 truncate text-[13px] text-neutral-950 dark:text-fg"
                        title="{{ $fileStorage->mount_path }}">{{ $fileStorage->mount_path }}</span>
                </div>
                @if ($hasPreviewSuffix)
                    <div class="volumes-col-pr min-w-0" @click.stop>
                        <span class="volumes-mobile-label volumes-field-label">PR suffix</span>
                        @if ($isDetachedFromCompose || !auth()->user()?->can('update', $resource))
                            <span>{{ $isPreviewSuffixEnabled ? 'Add suffix' : 'Share path' }}</span>
                        @else
                            <x-forms.listbox id="isPreviewSuffixEnabled" portal onChange="instantSave" :options="[
                                ['value' => true, 'label' => 'Add suffix'],
                                ['value' => false, 'label' => 'Share path'],
                            ]" />
                        @endif
                    </div>
                @endif
                <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                    @unless ($hasStatus)
                        <span class="data-table-cell-dash">-</span>
                    @endunless
                    @if ($isDetachedFromCompose)
                        <span class="table-badge table-badge-warning"
                            data-tooltip="The Docker Compose file does not mount this {{ $kind }} anymore. You can delete its Coolify record. The {{ $kind }} stays on the server."
                            data-tooltip-wrap>Not in Compose</span>
                    @endif
                    @if ($fileStorage->is_host_file)
                        <span class="table-badge"
                            data-tooltip="Coolify does not create, edit, load, chmod, or delete the source file."
                            data-tooltip-wrap>Host file</span>
                    @elseif ($fileStorage->is_too_large)
                        <span class="table-badge table-badge-warning"
                            data-tooltip="The file on the server is larger than 5 MB. Edit it on the server."
                            data-tooltip-wrap>Too large</span>
                    @elseif ($isReadOnly && !$isDetachedFromCompose)
                        <span class="table-badge" data-tooltip="The Compose file mounts this {{ $kind }} as read-only."
                            data-tooltip-wrap>Read-only</span>
                    @endif
                    @if ($fileStorage->is_based_on_git)
                        <span class="table-badge" data-tooltip="Coolify refreshes this file from the Git repository at each deployment."
                            data-tooltip-wrap>From Git</span>
                    @endif
                    @if ($hasEnabledBackup)
                        @if ($backupUrl)
                            <a href="{{ $backupUrl }}" @click.stop
                                class="table-badge table-badge-success underline-offset-2 hover:underline">Backup</a>
                        @else
                            <span class="table-badge table-badge-success">Backup</span>
                        @endif
                    @endif
                    @if (filled($fileStorage->variable_fs_path))
                        <span class="table-badge table-badge-warning"
                            data-tooltip="This storage keeps its saved host path. The variable in the Compose file gives {{ $fileStorage->variable_fs_path }}."
                            data-tooltip-wrap>Saved path</span>
                    @endif
                </div>
                <div class="flex items-center justify-end" @click.stop>
                    @can('update', $resource)
                        <x-table.row-actions label="Storage actions">
                            @if ($canEditSettings)
                                <x-table.row-action @click="modalOpen = true">Edit</x-table.row-action>
                            @endif
                            @if ($hasContent)
                                <x-table.row-action wire:click="loadStorageOnServer">Load from server</x-table.row-action>
                            @endif
                            @if (filled($fileStorage->variable_fs_path))
                                <x-modal-confirmation buttonFullWidth title="Use the Variable Path?" submitAction="useVariablePath"
                                    :confirmWithText="false" :confirmWithPassword="false"
                                    warningMessage="The data stays at the saved host path. Coolify does not copy or delete it."
                                    :actions="[
                                        'From the next deployment, the storage mounts ' . $fileStorage->variable_fs_path . '.',
                                        'Coolify does not move the data from ' . $fileStorage->fs_path . '. Copy it yourself if you need it.',
                                    ]" step2ButtonText="Use the variable path">
                                    <x-slot:trigger>
                                        <x-table.row-action>Use the variable path</x-table.row-action>
                                    </x-slot:trigger>
                                </x-modal-confirmation>
                            @endif
                            @if (!$isDetachedFromCompose && !$fileStorage->is_host_file)
                                @if ($fileStorage->is_directory)
                                    <x-modal-confirmation buttonFullWidth :ignoreWire="false" title="Confirm Directory Conversion to File?"
                                        submitAction="convertToFile" :actions="[
                                            'All files in this directory will be permanently deleted and an empty file will be created in its place.',
                                        ]" confirmationText="{{ $fs_path }}"
                                        :confirmationLabel="$confirmationLabel" shortConfirmationLabel="Filepath"
                                        :confirmWithPassword="false" step2ButtonText="Convert to file">
                                        <x-slot:trigger>
                                            <x-table.row-action>Convert to file</x-table.row-action>
                                        </x-slot:trigger>
                                    </x-modal-confirmation>
                                @elseif (!$fileStorage->is_binary && !$fileStorage->is_too_large)
                                    <x-modal-confirmation buttonFullWidth :ignoreWire="false" title="Confirm File Conversion to Directory?"
                                        submitAction="convertToDirectory" :actions="[
                                            'The selected file will be permanently deleted and an empty directory will be created in its place.',
                                        ]" confirmationText="{{ $fs_path }}"
                                        :confirmationLabel="$confirmationLabel" shortConfirmationLabel="Filepath"
                                        :confirmWithPassword="false" step2ButtonText="Convert to directory">
                                        <x-slot:trigger>
                                            <x-table.row-action>Convert to directory</x-table.row-action>
                                        </x-slot:trigger>
                                    </x-modal-confirmation>
                                @endif
                            @endif
                            @if ($canConfigureBackup)
                                <x-modal-input isFullWidth title="Configure Directory Backup" :wireIgnore="false" :closeEvent="false">
                                    <x-slot:content>
                                        <x-table.row-action>Configure backup</x-table.row-action>
                                    </x-slot:content>
                                    <livewire:project.application.backup.create :application="$resource"
                                        :selected-target-key="'directory:' . $fileStorage->id"
                                        wire:key="configure-directory-backup-{{ $fileStorage->id }}" />
                                </x-modal-input>
                            @endif
                            @if ($canDelete)
                                <x-slot:danger>
                                    <x-modal-confirmation buttonFullWidth :ignoreWire="false"
                                        :title="$isDetachedFromCompose ? 'Delete the Storage Record?' : 'Confirm ' . ucfirst($kind) . ' Deletion?'"
                                        submitAction="delete" :checkboxes="$deletionCheckboxes" :actions="$deletionActions"
                                        confirmationText="{{ $fs_path }}" :confirmationLabel="$confirmationLabel"
                                        shortConfirmationLabel="Filepath">
                                        <x-slot:trigger>
                                            <x-table.row-action>{{ $isDetachedFromCompose ? 'Delete record' : 'Delete' }}</x-table.row-action>
                                        </x-slot:trigger>
                                    </x-modal-confirmation>
                                </x-slot:danger>
                            @endif
                        </x-table.row-actions>
                    @endcan
                </div>
            </div>
        </x-slot:content>

        @if ($canEditSettings)
            <form wire:submit="submit" class="application-settings-form flex flex-col gap-4">
                @if ($hasContent)
                    @if (data_get($resource, 'settings.is_preserve_repository_enabled'))
                        <div class="w-full sm:w-96">
                            @can('update', $resource)
                                <x-forms.checkbox instantSave label="Is this based on the Git repository?"
                                    id="isBasedOnGit" :disabled="$isReadOnly"></x-forms.checkbox>
                            @else
                                <x-forms.checkbox disabled label="Is this based on the Git repository?"
                                    id="isBasedOnGit"></x-forms.checkbox>
                            @endcan
                        </div>
                    @endif
                    @can('update', $resource)
                        @if ($fileStorage->is_too_large)
                            <x-callout type="warning" title="File too large">
                                The file on the server is larger than 5 MB and cannot be edited here. Edit it on the server.
                            </x-callout>
                        @else
                            <x-forms.textarea
                                label="{{ $fileStorage->is_based_on_git ? 'Content (refreshed after a successful deployment)' : 'Content' }}"
                                helper="The content shown may be outdated. Click 'Load from server' to fetch the latest version."
                                rows="14" id="content" :useMonacoEditor="$isYamlFile" monacoEditorLanguage="yaml"
                                :disabled="$isReadOnly"
                                readonly="{{ $fileStorage->is_based_on_git || $fileStorage->is_binary }}"></x-forms.textarea>
                        @endif
                    @else
                        <x-callout type="info" title="Hidden (only admins can view)">
                            The file content can contain secrets.
                        </x-callout>
                    @endcan
                @endif
            </form>
            @if ($hasContent)
                @can('update', $resource)
                    <x-slot:footer>
                        <x-forms.button type="button" wire:click="loadStorageOnServer">Load from server</x-forms.button>
                        @if (!$isReadOnly && !$fileStorage->is_based_on_git && !$fileStorage->is_binary && !$fileStorage->is_too_large)
                            <x-forms.button type="button" wire:click="submit" isHighlighted>Save</x-forms.button>
                        @endif
                    </x-slot:footer>
                @endcan
            @endif
        @endif
    </x-modal-input>
</div>
