@php
    $hasSourcePaths = $resource->persistentStorages->contains(fn ($storage) => filled($storage->host_path));
    $gridClass = match (true) {
        $supportsPreviewSuffix => 'volumes-table-grid-with-pr',
        $showActionsColumn => 'volumes-table-grid',
        default => 'volumes-table-grid-readonly',
    };
@endphp

<div class="flex w-full flex-col">
    @if (data_get($resource, 'build_pack') === 'dockercompose')
        <div
            class="border-b border-neutral-200 px-4 py-3 text-[13px] leading-5 text-amber-800 dark:border-white/[0.08] dark:text-amber-300/90">
            Docker Compose volume mounts are read-only here. Edit the compose file and reload it to change volumes.
        </div>
    @endif

    @if ($resource->persistentStorages->isNotEmpty())
        <div class="data-table w-full">
            <div class="data-table-header {{ $gridClass }} {{ $hasSourcePaths ? 'has-source' : '' }}">
                <span>Storage Name</span>
                @if ($hasSourcePaths)
                    <span>Source Path</span>
                @endif
                <span>Destination Path</span>
                @if ($supportsPreviewSuffix)
                    <div class="volumes-col-pr flex items-center gap-1.5">
                        <span>PR suffix</span>
                        <x-helper helper="Adds -pr-N to the storage name or path so each preview uses isolated data. Disabling it shares production data with previews." />
                    </div>
                @endif
                <span class="volumes-col-backup text-center">Backup</span>
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
                    $backupMeta = $volumeBackupMeta[$id] ?? ['enabled' => false, 's3' => false, 'url' => null];
                    $hasEnabledBackup = $backupMeta['enabled'];
                    $hasS3Backup = $backupMeta['s3'];
                    $backupUrl = $backupMeta['url'];
                    $inputsReadonly = $form['isReadOnly'];
                @endphp

                @if ($inputsReadonly)
                    <div class="env-table-item" wire:key="storage-row-{{ $id }}">
                        <div class="data-table-row {{ $gridClass }} {{ $hasSourcePaths ? 'has-source' : '' }} text-[13px] text-neutral-700 dark:text-fg-dim">
                            <div class="volumes-cell-name min-w-0">
                                <span class="volumes-mobile-label volumes-field-label">Storage Name</span>
                                <div class="flex min-w-0 items-center gap-2">
                                    <span
                                        class="min-w-0 truncate text-[13px] font-medium text-neutral-950 dark:text-fg"
                                        title="{{ $form['name'] }}">{{ $form['name'] }}</span>
                                </div>
                                @if ($form['replacedExternalVolume'])
                                    <span class="block text-xs text-amber-800 dark:text-amber-300/90">
                                        Replaces the external volume '{{ $form['replacedExternalVolume'] }}'. Copy the data into the external volume, then delete this entry to use it.
                                    </span>
                                @endif
                                @if ($form['ignoresDriverOptions'])
                                    <span class="block text-xs text-amber-800 dark:text-amber-300/90">
                                        Coolify does not apply the driver options of this volume because it was created before they were supported. To apply them: stop the resource, back up any data you need, delete this entry together with the Docker volume, then redeploy.
                                    </span>
                                @endif
                            </div>

                            @if ($hasSourcePaths)
                                <div class="volumes-cell-source min-w-0">
                                    <span class="volumes-mobile-label volumes-field-label">Source Path</span>
                                    @if (filled($storage->host_path))
                                        <x-forms.input aria-label="Source Path" :value="$storage->host_path" readonly />
                                    @else
                                        <span class="data-table-cell-dash">-</span>
                                    @endif
                                </div>
                            @endif

                            <div class="volumes-cell-dest min-w-0">
                                <span class="volumes-mobile-label volumes-field-label">Destination Path</span>
                                <span
                                    class="block min-w-0 truncate text-[13px] text-neutral-950 dark:text-fg"
                                    title="{{ $form['mountPath'] }}">{{ $form['mountPath'] }}</span>
                            </div>

                            @if ($supportsPreviewSuffix)
                                <div class="volumes-col-pr min-w-0">
                                    <div class="volumes-mobile-label volumes-field-label flex items-center gap-1.5">
                                        <span>PR suffix</span>
                                        <x-helper helper="Adds -pr-N to the storage name or path so each preview uses isolated data. Disabling it shares production data with previews." />
                                    </div>
                                    <span>{{ $form['isPreviewSuffixEnabled'] ? 'Add suffix' : 'Share volume' }}</span>
                                </div>
                            @endif

                            <div class="volumes-col-backup flex items-center justify-center gap-1.5">
                                <span class="volumes-mobile-label volumes-field-label">Backup</span>
                                @if ($hasEnabledBackup)
                                    @if ($backupUrl)
                                        <a href="{{ $backupUrl }}" @class([
                                            'table-badge underline-offset-2 hover:underline',
                                            'table-badge-success' => $hasS3Backup,
                                        ])
                                            title="Volume backup is enabled"
                                            aria-label="{{ $hasS3Backup ? 'Backups are saved to S3' : 'Backups are stored locally only' }}">
                                            {{ $hasS3Backup ? 'S3' : 'Local' }}
                                        </a>
                                    @else
                                        <span @class(['table-badge', 'table-badge-success' => $hasS3Backup])
                                            title="Volume backup is enabled"
                                            aria-label="{{ $hasS3Backup ? 'Backups are saved to S3' : 'Backups are stored locally only' }}">
                                            {{ $hasS3Backup ? 'S3' : 'Local' }}
                                        </span>
                                    @endif
                                @else
                                    <span class="data-table-cell-dash">-</span>
                                @endif
                            </div>

                            @if ($showBackupAction)
                                <div
                                    class="volumes-col-actions volumes-cell-actions flex flex-nowrap items-center justify-end gap-1.5">
                                    @if ($canUpdate && ! $form['canDeleteStale'] && ! $form['canDeleteToApplyDriverOptions'])
                                        <x-modal-input title="Configure Volume Backup" :wireIgnore="false">
                                            <x-slot:content>
                                                <button type="button" class="icon-button shrink-0" title="Configure volume backup"
                                                    aria-label="Configure backup for {{ $form['name'] }}">
                                                    <x-reicon name="database" class="size-3.5" />
                                                </button>
                                            </x-slot:content>
                                            @if ($resource instanceof \App\Models\Application)
                                                <livewire:project.application.backup.create :application="$resource"
                                                    :selected-target-key="'volume:' . $id"
                                                    wire:key="configure-volume-backup-{{ $id }}" />
                                            @else
                                                <livewire:project.service.volume-backup.create :service="$resource->service"
                                                    :selected-target-key="'volume:' . $id"
                                                    wire:key="configure-service-volume-backup-{{ $id }}" />
                                            @endif
                                        </x-modal-input>
                                    @elseif ($canUpdate || $form['canDeleteStale'] || $form['canDeleteToApplyDriverOptions'])
                                        <x-table.row-actions label="Storage actions">
                                            @if ($canUpdate)
                                                <x-modal-input title="Configure Volume Backup" :wireIgnore="false">
                                                    <x-slot:content>
                                                        <x-table.row-action>Backup</x-table.row-action>
                                                    </x-slot:content>
                                                    @if ($resource instanceof \App\Models\Application)
                                                        <livewire:project.application.backup.create :application="$resource"
                                                            :selected-target-key="'volume:' . $id"
                                                            wire:key="configure-volume-backup-{{ $id }}" />
                                                    @else
                                                        <livewire:project.service.volume-backup.create :service="$resource->service"
                                                            :selected-target-key="'volume:' . $id"
                                                            wire:key="configure-service-volume-backup-{{ $id }}" />
                                                    @endif
                                                </x-modal-input>
                                            @endif
                                            @if ($form['canDeleteStale'] || $form['canDeleteToApplyDriverOptions'])
                                                <x-slot:danger>
                                                    @if ($form['canDeleteStale'])
                                                        <x-modal-confirmation title="Remove stale volume entry?" buttonFullWidth
                                                            submitAction="delete({{ $id }})"
                                                            :checkboxes="[[
                                                                'id' => 'deleteDockerVolume',
                                                                'label' => 'Also permanently delete the Docker volume and all its data.',
                                                                'default_warning' => 'The Docker volume and its data will not be deleted.',
                                                            ]]"
                                                            :actions="array_values(array_filter([
                                                                'This removes only the stale volume entry from Coolify.',
                                                                $form['replacedExternalVolume']
                                                                    ? 'The next deployment uses the external volume \''.$form['replacedExternalVolume'].'\' instead of this volume.'
                                                                    : null,
                                                            ]))"
                                                            :warningMessage="$form['usedByContainers'] !== []
                                                                ? e('This volume is still in use by: '.implode(', ', $form['usedByContainers']).'. After you delete this entry, the next deployment starts the resource without the data in this volume. Back up the data first if you need it.')
                                                                : null" confirmationText="{{ $form['name'] }}"
                                                            confirmationLabel="Please confirm by entering the Storage Name below"
                                                            shortConfirmationLabel="Storage Name">
                                                            <x-slot:trigger>
                                                                <x-table.row-action>Delete stale entry</x-table.row-action>
                                                            </x-slot:trigger>
                                                        </x-modal-confirmation>
                                                    @endif
                                                    @if ($form['canDeleteToApplyDriverOptions'])
                                                        <x-modal-confirmation title="Delete volume entry to apply driver options?" buttonFullWidth
                                                            submitAction="delete({{ $id }})"
                                                            :checkboxes="[[
                                                                'id' => 'deleteDockerVolume',
                                                                'label' => 'Permanently delete the Docker volume and all data in it. The next deployment creates the volume again with the driver options.',
                                                                'default_warning' => 'The Docker volume and its data stay on the server. The driver options are not applied, because Docker keeps using the existing volume.',
                                                            ]]"
                                                            :actions="[
                                                                'Remove the storage entry from Coolify.',
                                                            ]"
                                                            warningMessage="Stop the resource first: Docker cannot delete a volume that a running container uses. If the volume contains data you need, use Backup before you delete it." confirmationText="{{ $form['name'] }}"
                                                            confirmationLabel="Please confirm by entering the Storage Name below"
                                                            shortConfirmationLabel="Storage Name">
                                                            <x-slot:trigger>
                                                                <x-table.row-action>Delete</x-table.row-action>
                                                            </x-slot:trigger>
                                                        </x-modal-confirmation>
                                                    @endif
                                                </x-slot:danger>
                                            @endif
                                        </x-table.row-actions>
                                    @else
                                        <span class="text-neutral-400 dark:text-fg-faint">—</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @else
                    <form wire:submit="submit({{ $id }})" class="env-table-item" wire:key="storage-row-{{ $id }}">
                        <div class="data-table-row {{ $gridClass }} {{ $hasSourcePaths ? 'has-source' : '' }}">
                            <div class="volumes-cell-name min-w-0">
                                <span class="volumes-mobile-label volumes-field-label">Storage Name</span>
                                <div class="flex min-w-0 items-center gap-2">
                                    <div class="min-w-0 flex-1">
                                        <x-forms.input id="forms.{{ $id }}.name" required :readonly="$form['isShared']" />
                                    </div>
                                </div>
                                @if ($storage->standaloneSqlite)
                                    <a href="{{ $storage->standaloneSqlite->link() }}"
                                        class="block text-xs text-neutral-500 underline underline-offset-2 hover:text-black dark:text-fg-dim dark:hover:text-fg">SQLite
                                        database {{ $storage->standaloneSqlite->name }}</a>
                                @endif
                            </div>

                            @if ($hasSourcePaths)
                                <div class="volumes-cell-source min-w-0">
                                    <span class="volumes-mobile-label volumes-field-label">Source Path</span>
                                    @if (filled($storage->host_path))
                                        <x-forms.input aria-label="Source Path" :value="$storage->host_path" readonly />
                                    @else
                                        <span class="data-table-cell-dash">-</span>
                                    @endif
                                </div>
                            @endif

                            <div class="volumes-cell-dest min-w-0">
                                <span class="volumes-mobile-label volumes-field-label">Destination Path</span>
                                <x-forms.input id="forms.{{ $id }}.mountPath" required
                                    placeholder="/path/in/container" />
                            </div>

                            @if ($supportsPreviewSuffix)
                                <div class="volumes-col-pr min-w-0">
                                    <div class="volumes-mobile-label volumes-field-label flex items-center gap-1.5">
                                        <span>PR suffix</span>
                                        <x-helper helper="Adds -pr-N to the storage name or path so each preview uses isolated data. Disabling it shares production data with previews." />
                                    </div>
                                    <x-forms.listbox id="forms.{{ $id }}.isPreviewSuffixEnabled" portal :options="[
                                        ['value' => true, 'label' => 'Add suffix'],
                                        ['value' => false, 'label' => 'Share volume'],
                                    ]" canGate="update" :canResource="$resource" />
                                </div>
                            @endif

                            <div class="volumes-col-backup flex items-center justify-center gap-1.5">
                                <span class="volumes-mobile-label volumes-field-label">Backup</span>
                                @if ($hasEnabledBackup)
                                    @if ($backupUrl)
                                        <a href="{{ $backupUrl }}" @class([
                                            'table-badge underline-offset-2 hover:underline',
                                            'table-badge-success' => $hasS3Backup,
                                        ])
                                            title="Volume backup is enabled"
                                            aria-label="{{ $hasS3Backup ? 'Backups are saved to S3' : 'Backups are stored locally only' }}">
                                            {{ $hasS3Backup ? 'S3' : 'Local' }}
                                        </a>
                                    @else
                                        <span @class(['table-badge', 'table-badge-success' => $hasS3Backup])
                                            title="Volume backup is enabled"
                                            aria-label="{{ $hasS3Backup ? 'Backups are saved to S3' : 'Backups are stored locally only' }}">
                                            {{ $hasS3Backup ? 'S3' : 'Local' }}
                                        </span>
                                    @endif
                                @else
                                    <span class="data-table-cell-dash">-</span>
                                @endif
                            </div>

                            <div
                                class="volumes-col-actions volumes-cell-actions flex flex-nowrap items-center justify-end gap-1.5">
                                <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">Update</button>
                                @php
                                    $hasBackupAction = auth()->user()?->can('update', $resource)
                                        && (($showBackupAction && ! $form['isShared'])
                                            || (method_exists($resource, 'isBackupSolutionAvailable') && $resource->isBackupSolutionAvailable()));
                                @endphp
                                @if ($form['isShared'] && ! $hasBackupAction)
                                    <button type="button" wire:click="submit({{ $id }})" class="icon-button shrink-0"
                                        title="Update storage" aria-label="Update {{ $form['name'] }}">
                                        <x-reicon name="check" class="size-3.5" />
                                    </button>
                                @else
                                    <x-table.row-actions label="Storage actions">
                                        <x-table.row-action wire:click="submit({{ $id }})">Update</x-table.row-action>
                                        @can('update', $resource)
                                            @if ($showBackupAction && ! $form['isShared'])
                                                <x-modal-input title="Configure Volume Backup" :wireIgnore="false">
                                                    <x-slot:content>
                                                        <x-table.row-action>Backup</x-table.row-action>
                                                    </x-slot:content>
                                                    @if ($resource instanceof \App\Models\Application)
                                                        <livewire:project.application.backup.create :application="$resource"
                                                            :selected-target-key="'volume:' . $id"
                                                            wire:key="configure-volume-backup-{{ $id }}" />
                                                    @else
                                                        <livewire:project.service.volume-backup.create :service="$resource->service"
                                                            :selected-target-key="'volume:' . $id"
                                                            wire:key="configure-service-volume-backup-{{ $id }}" />
                                                    @endif
                                                </x-modal-input>
                                            @elseif (method_exists($resource, 'isBackupSolutionAvailable') && $resource->isBackupSolutionAvailable())
                                                <x-modal-input title="New Scheduled Backup" :wireIgnore="false">
                                                    <x-slot:content>
                                                        <x-table.row-action>Backup</x-table.row-action>
                                                    </x-slot:content>
                                                    <livewire:project.database.create-scheduled-backup :database="$resource"
                                                        wire:key="configure-database-backup-{{ $id }}" />
                                                </x-modal-input>
                                            @endif
                                        @endcan
                                        @unless ($form['isShared'])
                                            <x-slot:danger>
                                                <x-modal-confirmation title="Confirm persistent storage deletion?" buttonFullWidth
                                                    submitAction="delete({{ $id }})" :actions="[
                                                        'The selected persistent storage/volume will be permanently deleted.',
                                                        'If the persistent storage/volume is actvily used by a resource data will be lost.',
                                                    ]" confirmationText="{{ $form['name'] }}"
                                                    confirmationLabel="Please confirm the execution of the actions by entering the Storage Name below"
                                                    shortConfirmationLabel="Storage Name">
                                                    <x-slot:trigger>
                                                        <x-table.row-action>Delete</x-table.row-action>
                                                    </x-slot:trigger>
                                                </x-modal-confirmation>
                                            </x-slot:danger>
                                        @endunless
                                    </x-table.row-actions>
                                @endif
                            </div>
                        </div>
                    </form>
                @endif
            @endforeach
        </div>
    @endif

    @if ($pendingRename)
        <div x-data="{ modalOpen: true }" wire:key="rename-volume-modal-{{ md5(json_encode($pendingRename)) }}"
            @keydown.escape.window="if (modalOpen) { modalOpen = false; $wire.cancelRename({{ $pendingRename['storageId'] }}) }"
            :class="{ 'z-40': modalOpen }" class="relative h-auto w-auto">
            <template x-teleport="body">
                <div x-show="modalOpen"
                    class="fixed inset-0 z-99 flex min-h-full items-center justify-center overflow-y-auto p-4" x-cloak>
                    <div x-show="modalOpen" class="absolute inset-0 bg-black/50 backdrop-blur-[2px]"></div>
                    <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen" role="dialog" aria-modal="true"
                        x-transition:enter="ease-out duration-100"
                        x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                        class="application-settings-form application-settings-section relative w-full lg:min-w-[36rem] lg:max-w-2xl"
                        style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                        <header>
                            <h3>Change the volume name?</h3>
                            <button type="button" aria-label="Close"
                                @click="modalOpen = false; $wire.cancelRename({{ $pendingRename['storageId'] }})"
                                class="flex size-7 items-center justify-center rounded-md text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg">
                                <x-reicon name="x" class="size-4" />
                            </button>
                        </header>
                        <div class="application-settings-section-body">
                            <x-callout type="warning" title="The data does not move to the new volume" class="mb-4">
                                <ul class="mt-2 ml-4 list-disc">
                                    <li>Changing the name does not rename or move the Docker volume.</li>
                                    <li>After the next restart or deployment, the resource uses the Docker volume <strong>{{ $pendingRename['to'] }}</strong>. If that volume does not exist, Docker creates it empty.</li>
                                    <li>Your data stays in <strong>{{ $pendingRename['from'] }}</strong>. Coolify does not copy it, and does not delete it when you delete this resource.</li>
                                    <li>To keep your data, stop the resource and copy the data into the new volume before you start it again.</li>
                                </ul>
                            </x-callout>

                            <div class="mt-4 flex flex-wrap justify-end gap-2 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                                <x-forms.button
                                    @click="modalOpen = false; $wire.cancelRename({{ $pendingRename['storageId'] }})">
                                    Cancel
                                </x-forms.button>
                                <x-forms.button wire:click="submit({{ $pendingRename['storageId'] }}, true)"
                                    @click="modalOpen = false" isError>
                                    Change Volume Name
                                </x-forms.button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    @endif

</div>
