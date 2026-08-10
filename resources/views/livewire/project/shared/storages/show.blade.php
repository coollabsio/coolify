@php
    $showActionsColumn = $resource instanceof \App\Models\Application;
    $gridClass = match (true) {
        $supportsPreviewSuffix => 'volumes-table-grid-with-pr',
        $showActionsColumn => 'volumes-table-grid',
        default => 'volumes-table-grid-readonly',
    };
    $canUpdate = auth()->user()?->can('update', $resource) ?? false;
    $inputsReadonly = $isReadOnly || ! $canUpdate;
    $displayHostPath = filled($hostPath) ? $hostPath : '—';
@endphp

@if ($inputsReadonly)
    {{-- Read-only: plain data-table row (service / compose / no permission) --}}
    <div class="env-table-item" wire:key="storage-row-{{ $storage->id }}">
        <div class="data-table-row {{ $gridClass }} text-[13px] text-neutral-700 dark:text-fg-dim">
            <div class="volumes-cell-name min-w-0">
                <span class="volumes-mobile-label volumes-field-label">Volume Name</span>
                <div class="flex min-w-0 items-center gap-2">
                    <span class="min-w-0 truncate text-[13px] font-medium text-neutral-950 dark:text-fg"
                        title="{{ $name }}">{{ $name }}</span>
                    @if ($hasEnabledBackup)
                        @if ($backupUrl)
                            <a href="{{ $backupUrl }}"
                                class="table-badge table-badge-success shrink-0 underline-offset-2 hover:underline"
                                title="Volume backup is enabled">
                                Backup
                            </a>
                        @else
                            <span class="table-badge table-badge-success shrink-0" title="Volume backup is enabled">
                                Backup
                            </span>
                        @endif
                    @endif
                </div>
            </div>

            <div class="volumes-col-source min-w-0">
                <span class="volumes-mobile-label volumes-field-label">Source Path</span>
                <span class="block min-w-0 truncate text-[13px]" title="{{ $hostPath }}">
                    {{ $displayHostPath }}
                </span>
            </div>

            <div class="volumes-cell-dest min-w-0">
                <span class="volumes-mobile-label volumes-field-label">Destination Path</span>
                <span class="block min-w-0 truncate text-[13px] text-neutral-950 dark:text-fg"
                    title="{{ $mountPath }}">{{ $mountPath }}</span>
            </div>

            @if ($supportsPreviewSuffix)
                <div class="volumes-col-pr min-w-0">
                    <span class="volumes-mobile-label volumes-field-label">PR suffix</span>
                    <span>{{ $isPreviewSuffixEnabled ? 'Add suffix' : 'Share volume' }}</span>
                </div>
            @endif

            @if ($showActionsColumn)
                <div class="volumes-col-actions volumes-cell-actions flex flex-wrap items-center justify-end gap-1.5">
                    @if ($canUpdate)
                        @if ($showBackupModal)
                            <x-modal-input buttonTitle="Backup" title="Configure Volume Backup" :wireIgnore="false"
                                wireOpen="showBackupModal">
                                <livewire:project.application.backup.create :application="$resource"
                                    :selected-target-key="'volume:' . $storage->id"
                                    wire:key="configure-readonly-volume-backup-{{ $storage->id }}" />
                            </x-modal-input>
                        @else
                            <x-forms.button type="button" wire:click="openBackupModal" class="!px-2.5 !text-xs">
                                Backup
                            </x-forms.button>
                        @endif
                    @else
                        <span class="text-neutral-400 dark:text-fg-faint">—</span>
                    @endif
                </div>
            @endif
        </div>
    </div>
@else
    {{-- Editable volume row --}}
    <form wire:submit="submit" class="env-table-item" wire:key="storage-row-{{ $storage->id }}">
        <div class="data-table-row {{ $gridClass }}">
            <div class="volumes-cell-name min-w-0">
                <span class="volumes-mobile-label volumes-field-label">Volume Name</span>
                <div class="flex min-w-0 items-center gap-2">
                    <div class="min-w-0 flex-1">
                        <x-forms.input id="name" required />
                    </div>
                    @if ($hasEnabledBackup)
                        @if ($backupUrl)
                            <a href="{{ $backupUrl }}"
                                class="table-badge table-badge-success shrink-0 underline-offset-2 hover:underline"
                                title="Volume backup is enabled">
                                Backup
                            </a>
                        @else
                            <span class="table-badge table-badge-success shrink-0" title="Volume backup is enabled">
                                Backup
                            </span>
                        @endif
                    @endif
                </div>
            </div>

            <div class="volumes-col-source min-w-0">
                <span class="volumes-mobile-label volumes-field-label">Source Path</span>
                <x-forms.input id="hostPath" placeholder="Host path (optional)" />
            </div>

            <div class="volumes-cell-dest min-w-0">
                <span class="volumes-mobile-label volumes-field-label">Destination Path</span>
                <x-forms.input id="mountPath" required placeholder="/path/in/container" />
            </div>

            @if ($supportsPreviewSuffix)
                <div class="volumes-col-pr min-w-0">
                    <span class="volumes-mobile-label volumes-field-label">PR suffix</span>
                    <x-forms.listbox id="isPreviewSuffixEnabled" onChange="instantSave" :options="[
                        ['value' => true, 'label' => 'Add suffix'],
                        ['value' => false, 'label' => 'Share volume'],
                    ]" />
                </div>
            @endif

            <div class="volumes-col-actions volumes-cell-actions flex flex-wrap items-center justify-end gap-1.5">
                <x-forms.button type="submit" class="!px-2.5 !text-xs">
                    Update
                </x-forms.button>

                @if ($resource instanceof \App\Models\Application)
                    @if ($showBackupModal)
                        <x-modal-input buttonTitle="Backup" title="Configure Volume Backup" :wireIgnore="false"
                            wireOpen="showBackupModal">
                            <livewire:project.application.backup.create :application="$resource"
                                :selected-target-key="'volume:' . $storage->id"
                                wire:key="configure-volume-backup-{{ $storage->id }}" />
                        </x-modal-input>
                    @else
                        <x-forms.button type="button" wire:click="openBackupModal" class="!px-2.5 !text-xs">
                            Backup
                        </x-forms.button>
                    @endif
                @endif

                <x-modal-confirmation title="Confirm persistent storage deletion?" isErrorButton buttonTitle="Delete"
                    submitAction="delete" :actions="[
                        'The selected persistent storage/volume will be permanently deleted.',
                        'If the persistent storage/volume is actvily used by a resource data will be lost.',
                    ]" confirmationText="{{ $storage->name }}"
                    confirmationLabel="Please confirm the execution of the actions by entering the Storage Name below"
                    shortConfirmationLabel="Storage Name" />
            </div>
        </div>
    </form>
@endif
