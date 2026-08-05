@php
    $gridClass = match (true) {
        $supportsPreviewSuffix => 'volumes-table-grid-with-pr',
        $showActionsColumn => 'volumes-table-grid',
        default => 'volumes-table-grid-readonly',
    };
@endphp

<div class="flex w-full flex-col">
    @if ($isComposeOrService)
        <div
            class="border-b border-neutral-200 px-4 py-3 text-[13px] leading-5 text-amber-800 dark:border-white/[0.08] dark:text-amber-300/90">
            @if ($resource->type() === 'service')
                Service volume mounts are read-only here. Edit the Docker Compose file and reload it to change volumes.
            @else
                Docker Compose volume mounts are read-only here. Edit the compose file and reload it to change volumes.
            @endif
        </div>
    @endif

    @if ($resource->persistentStorages->isNotEmpty())
        <div class="data-table w-full">
            <div class="data-table-header {{ $gridClass }}">
                <span>Volume Name</span>
                <span class="volumes-col-source">Source Path</span>
                <span>Destination Path</span>
                @if ($supportsPreviewSuffix)
                    <span class="volumes-col-pr"
                        title="Whether preview deployments receive an isolated -pr-N volume suffix.">
                        PR suffix
                    </span>
                @endif
                @if ($showActionsColumn)
                    <span class="volumes-col-actions text-right">Actions</span>
                @endif
            </div>

            @foreach ($this->storages as $storage)
                @php
                    $id = $storage->id;
                    $form = $forms[$id] ?? null;
                    if (! $form) {
                        continue;
                    }
                    $backupMeta = $volumeBackupMeta[$id] ?? ['enabled' => false, 'url' => null];
                    $hasEnabledBackup = $backupMeta['enabled'];
                    $backupUrl = $backupMeta['url'];
                    $inputsReadonly = $form['isReadOnly'];
                    $displayHostPath = filled($form['hostPath']) ? $form['hostPath'] : '—';
                @endphp

                @if ($inputsReadonly)
                    <div class="env-table-item" wire:key="storage-row-{{ $id }}">
                        <div class="data-table-row {{ $gridClass }} text-[13px] text-neutral-700 dark:text-fg-dim">
                            <div class="volumes-cell-name min-w-0">
                                <span class="volumes-mobile-label volumes-field-label">Volume Name</span>
                                <div class="flex min-w-0 items-center gap-2">
                                    <span
                                        class="min-w-0 truncate font-mono text-[13px] font-medium text-neutral-950 dark:text-fg"
                                        title="{{ $form['name'] }}">{{ $form['name'] }}</span>
                                    @if ($hasEnabledBackup)
                                        @if ($backupUrl)
                                            <a href="{{ $backupUrl }}"
                                                class="table-badge table-badge-success shrink-0 underline-offset-2 hover:underline"
                                                title="Volume backup is enabled">Backup</a>
                                        @else
                                            <span class="table-badge table-badge-success shrink-0"
                                                title="Volume backup is enabled">Backup</span>
                                        @endif
                                    @endif
                                </div>
                            </div>

                            <div class="volumes-col-source min-w-0">
                                <span class="volumes-mobile-label volumes-field-label">Source Path</span>
                                <span class="block min-w-0 truncate font-mono text-[13px]"
                                    title="{{ $form['hostPath'] }}">{{ $displayHostPath }}</span>
                            </div>

                            <div class="volumes-cell-dest min-w-0">
                                <span class="volumes-mobile-label volumes-field-label">Destination Path</span>
                                <span
                                    class="block min-w-0 truncate font-mono text-[13px] text-neutral-950 dark:text-fg"
                                    title="{{ $form['mountPath'] }}">{{ $form['mountPath'] }}</span>
                            </div>

                            @if ($supportsPreviewSuffix)
                                <div class="volumes-col-pr min-w-0">
                                    <span class="volumes-mobile-label volumes-field-label">PR suffix</span>
                                    <span>{{ $form['isPreviewSuffixEnabled'] ? 'Add suffix' : 'Share volume' }}</span>
                                </div>
                            @endif

                            @if ($showActionsColumn)
                                <div
                                    class="volumes-col-actions volumes-cell-actions flex flex-wrap items-center justify-end gap-1.5">
                                    @if ($canUpdate)
                                        <x-forms.button type="button" wire:click="openBackupModal({{ $id }})"
                                            class="!px-2.5 !text-xs">
                                            Backup
                                        </x-forms.button>
                                    @else
                                        <span class="text-neutral-400 dark:text-fg-faint">—</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @else
                    <form wire:submit="submit({{ $id }})" class="env-table-item" wire:key="storage-row-{{ $id }}">
                        <div class="data-table-row {{ $gridClass }}">
                            <div class="volumes-cell-name min-w-0">
                                <span class="volumes-mobile-label volumes-field-label">Volume Name</span>
                                <div class="flex min-w-0 items-center gap-2">
                                    <div class="min-w-0 flex-1">
                                        <x-forms.input id="forms.{{ $id }}.name" required />
                                    </div>
                                    @if ($hasEnabledBackup)
                                        @if ($backupUrl)
                                            <a href="{{ $backupUrl }}"
                                                class="table-badge table-badge-success shrink-0 underline-offset-2 hover:underline"
                                                title="Volume backup is enabled">Backup</a>
                                        @else
                                            <span class="table-badge table-badge-success shrink-0"
                                                title="Volume backup is enabled">Backup</span>
                                        @endif
                                    @endif
                                </div>
                            </div>

                            <div class="volumes-col-source min-w-0">
                                <span class="volumes-mobile-label volumes-field-label">Source Path</span>
                                <x-forms.input id="forms.{{ $id }}.hostPath" placeholder="Host path (optional)" />
                            </div>

                            <div class="volumes-cell-dest min-w-0">
                                <span class="volumes-mobile-label volumes-field-label">Destination Path</span>
                                <x-forms.input id="forms.{{ $id }}.mountPath" required
                                    placeholder="/path/in/container" />
                            </div>

                            @if ($supportsPreviewSuffix)
                                <div class="volumes-col-pr min-w-0">
                                    <span class="volumes-mobile-label volumes-field-label">PR suffix</span>
                                    <x-forms.listbox id="forms.{{ $id }}.isPreviewSuffixEnabled" :options="[
                                        ['value' => true, 'label' => 'Add suffix'],
                                        ['value' => false, 'label' => 'Share volume'],
                                    ]" />
                                </div>
                            @endif

                            <div
                                class="volumes-col-actions volumes-cell-actions flex flex-wrap items-center justify-end gap-1.5">
                                <x-forms.button type="submit" class="!px-2.5 !text-xs">
                                    Update
                                </x-forms.button>

                                @if ($resource instanceof \App\Models\Application)
                                    <x-forms.button type="button" wire:click="openBackupModal({{ $id }})"
                                        class="!px-2.5 !text-xs">
                                        Backup
                                    </x-forms.button>
                                @endif

                                <x-modal-confirmation title="Confirm persistent storage deletion?" isErrorButton
                                    buttonTitle="Delete" submitAction="delete({{ $id }})" :actions="[
                                        'The selected persistent storage/volume will be permanently deleted.',
                                        'If the persistent storage/volume is actvily used by a resource data will be lost.',
                                    ]" confirmationText="{{ $form['name'] }}"
                                    confirmationLabel="Please confirm the execution of the actions by entering the Storage Name below"
                                    shortConfirmationLabel="Storage Name" />
                            </div>
                        </div>
                    </form>
                @endif
            @endforeach
        </div>
    @endif

    {{-- Single shared backup configurator (mounted only when opened) --}}
    @if ($backupModalStorageId && $resource instanceof \App\Models\Application)
        <div wire:key="shared-volume-backup-modal-{{ $backupModalStorageId }}" x-data="{ modalOpen: true }"
            x-init="$watch('modalOpen', value => { if (!value) { $wire.closeBackupModal() } })"
            @keydown.window.escape="modalOpen = false">
            <template x-teleport="body">
                <div x-show="modalOpen" class="fixed inset-0 z-99 overflow-y-auto">
                    <div x-show="modalOpen" x-transition:enter="ease-out duration-100"
                        x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                        x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
                        x-transition:leave-end="opacity-0"
                        class="absolute inset-0 w-full h-full bg-black/50 backdrop-blur-[2px]"
                        @click="modalOpen = false"></div>
                    <div class="relative flex min-h-full items-start justify-center p-4 sm:items-center"
                        @click.self="modalOpen = false">
                        <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen"
                            x-transition:enter="ease-out duration-100"
                            x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                            x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                            x-transition:leave="ease-in duration-100"
                            x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                            x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                            class="application-settings-form application-settings-section relative max-h-[calc(100dvh-2rem)] w-full lg:w-auto lg:min-w-2xl lg:max-w-4xl"
                            style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                            <header class="flex-nowrap!">
                                <h3 class="min-w-0 flex-1 truncate">Configure Volume Backup</h3>
                                <button type="button" @click="modalOpen = false"
                                    class="flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-md text-neutral-500 outline-0 transition-colors hover:bg-neutral-100 hover:text-black focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-accent dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg">
                                    <x-reicon name="x" class="size-4" />
                                </button>
                            </header>
                            <div class="application-settings-section-body min-h-0 flex-1 overflow-y-auto"
                                style="-webkit-overflow-scrolling: touch;">
                                <livewire:project.application.backup.create :application="$resource"
                                    :selected-target-key="'volume:' . $backupModalStorageId"
                                    wire:key="shared-configure-volume-backup-{{ $backupModalStorageId }}" />
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    @endif
</div>
