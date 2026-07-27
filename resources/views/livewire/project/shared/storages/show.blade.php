<div>
    <form wire:submit='submit'
        class="flex flex-col gap-4 rounded-lg bg-neutral-50 p-4 ring-1 ring-neutral-200 dark:bg-white/[0.025] dark:ring-white/[0.07]">
        @if ($isReadOnly)
            @if (!$storage->isServiceResource() && !$storage->isDockerComposeResource())
                <div class="w-full p-2 text-sm rounded bg-warning/10 text-warning">
                    This volume is mounted as read-only and cannot be modified from the UI.
                </div>
            @endif
            @if ($isFirst)
                <div class="grid w-full gap-4 md:grid-cols-3">
                    @if (
                        $storage->resource_type === 'App\Models\ServiceApplication' ||
                            $storage->resource_type === 'App\Models\ServiceDatabase')
                        <x-forms.input id="name" label="Volume Name" required readonly
                            helper="Warning: Changing the volume name after the initial start could cause problems. Only use it when you know what are you doing.">
                            <x-slot:labelSuffix>
                                @if ($hasEnabledBackup)
                                    <x-status-badge :as="$backupUrl ? 'a' : 'span'" :href="$backupUrl"
                                        status="Backup enabled" type="success"
                                        :class="$backupUrl ? 'cursor-pointer underline' : null" />
                                @endif
                            </x-slot:labelSuffix>
                        </x-forms.input>
                    @else
                        <x-forms.input id="name" label="Volume Name" required readonly
                            helper="Warning: Changing the volume name after the initial start could cause problems. Only use it when you know what are you doing.">
                            <x-slot:labelSuffix>
                                @if ($hasEnabledBackup)
                                    <x-status-badge :as="$backupUrl ? 'a' : 'span'" :href="$backupUrl"
                                        status="Backup enabled" type="success"
                                        :class="$backupUrl ? 'cursor-pointer underline' : null" />
                                @endif
                            </x-slot:labelSuffix>
                        </x-forms.input>
                    @endif
                    @if ($isService || $startedAt)
                        <x-forms.input id="hostPath" readonly helper="Directory on the host system."
                            label="Source Path"
                            helper="Warning: Changing the source path after the initial start could cause problems. Only use it when you know what are you doing." />
                        <x-forms.input id="mountPath" label="Destination Path"
                            helper="Directory inside the container." required readonly />
                    @else
                        <x-forms.input id="hostPath" readonly helper="Directory on the host system."
                            label="Source Path"
                            helper="Warning: Changing the source path after the initial start could cause problems. Only use it when you know what are you doing." />
                        <x-forms.input id="mountPath" label="Destination Path"
                            helper="Directory inside the container." required readonly />
                    @endif
                </div>
            @else
                <div class="grid w-full gap-4 md:grid-cols-3">
                    <x-forms.input id="name" :label="$hasEnabledBackup ? 'Volume Name' : null" required readonly>
                        <x-slot:labelSuffix>
                            @if ($hasEnabledBackup)
                                <x-status-badge :as="$backupUrl ? 'a' : 'span'" :href="$backupUrl"
                                    status="Backup enabled" type="success"
                                    :class="$backupUrl ? 'cursor-pointer underline' : null" />
                            @endif
                        </x-slot:labelSuffix>
                    </x-forms.input>
                    <x-forms.input id="hostPath" readonly />
                    <x-forms.input id="mountPath" required readonly />
                </div>
            @endif
            @if (!$isService)
                @can('update', $resource)
                    <div class="w-full sm:w-96">
                        <x-forms.listbox id="isPreviewSuffixEnabled" label="PR deployment suffix"
                            helper="Choose whether preview deployments receive an isolated -pr-N volume suffix."
                            onChange="instantSave" :options="[
                                ['value' => true, 'label' => 'Add suffix'],
                                ['value' => false, 'label' => 'Share volume'],
                            ]" />
                    </div>
                @endcan
            @endif
            @if ($resource instanceof \App\Models\Application)
                @can('update', $resource)
                    <x-modal-input buttonTitle="Configure Backup" title="Configure Volume Backup" :wireIgnore="false">
                        <livewire:project.application.backup.create :application="$resource"
                            :selected-target-key="'volume:' . $storage->id"
                            wire:key="configure-readonly-volume-backup-{{ $storage->id }}" />
                    </x-modal-input>
                @endcan
            @endif
        @else
            @can('update', $resource)
                @if ($isFirst)
                    <div class="grid w-full gap-4 md:grid-cols-3">
                        <x-forms.input id="name" label="Volume Name" required>
                            <x-slot:labelSuffix>
                                @if ($hasEnabledBackup)
                                    <x-status-badge :as="$backupUrl ? 'a' : 'span'" :href="$backupUrl"
                                        status="Backup enabled" type="success"
                                        :class="$backupUrl ? 'cursor-pointer underline' : null" />
                                @endif
                            </x-slot:labelSuffix>
                        </x-forms.input>
                        <x-forms.input id="hostPath" helper="Directory on the host system." label="Source Path" />
                        <x-forms.input id="mountPath" label="Destination Path"
                            helper="Directory inside the container." required />
                    </div>
                @else
                    <div class="grid w-full gap-4 md:grid-cols-3">
                        <x-forms.input id="name" :label="$hasEnabledBackup ? 'Volume Name' : null" required>
                            <x-slot:labelSuffix>
                                @if ($hasEnabledBackup)
                                    <x-status-badge :as="$backupUrl ? 'a' : 'span'" :href="$backupUrl"
                                        status="Backup enabled" type="success"
                                        :class="$backupUrl ? 'cursor-pointer underline' : null" />
                                @endif
                            </x-slot:labelSuffix>
                        </x-forms.input>
                        <x-forms.input id="hostPath" />
                        <x-forms.input id="mountPath" required />
                    </div>
                @endif
                @if (!$isService)
                    <div class="w-full sm:w-96">
                        <x-forms.listbox id="isPreviewSuffixEnabled" label="PR deployment suffix"
                            helper="Choose whether preview deployments receive an isolated -pr-N volume suffix."
                            onChange="instantSave" :options="[
                                ['value' => true, 'label' => 'Add suffix'],
                                ['value' => false, 'label' => 'Share volume'],
                            ]" />
                    </div>
                @endif
                <div class="flex gap-2">
                    <x-forms.button type="submit">
                        Update
                    </x-forms.button>
                    @if ($resource instanceof \App\Models\Application)
                        <x-modal-input buttonTitle="Configure Backup" title="Configure Volume Backup" :wireIgnore="false">
                            <livewire:project.application.backup.create :application="$resource"
                                :selected-target-key="'volume:' . $storage->id"
                                wire:key="configure-volume-backup-{{ $storage->id }}" />
                        </x-modal-input>
                    @endif
                    <x-modal-confirmation title="Confirm persistent storage deletion?" isErrorButton buttonTitle="Delete"
                        submitAction="delete" :actions="[
                            'The selected persistent storage/volume will be permanently deleted.',
                            'If the persistent storage/volume is actvily used by a resource data will be lost.',
                        ]" confirmationText="{{ $storage->name }}"
                        confirmationLabel="Please confirm the execution of the actions by entering the Storage Name below"
                        shortConfirmationLabel="Storage Name" />
                </div>
            @else
                @if ($isFirst)
                    <div class="grid w-full gap-4 md:grid-cols-3">
                        <x-forms.input id="name" label="Volume Name" required disabled>
                            <x-slot:labelSuffix>
                                @if ($hasEnabledBackup)
                                    <x-status-badge :as="$backupUrl ? 'a' : 'span'" :href="$backupUrl"
                                        status="Backup enabled" type="success"
                                        :class="$backupUrl ? 'cursor-pointer underline' : null" />
                                @endif
                            </x-slot:labelSuffix>
                        </x-forms.input>
                        <x-forms.input id="hostPath" helper="Directory on the host system." label="Source Path"
                            disabled />
                        <x-forms.input id="mountPath" label="Destination Path"
                            helper="Directory inside the container." required disabled />
                    </div>
                @else
                    <div class="grid w-full gap-4 md:grid-cols-3">
                        <x-forms.input id="name" :label="$hasEnabledBackup ? 'Volume Name' : null" required disabled>
                            <x-slot:labelSuffix>
                                @if ($hasEnabledBackup)
                                    <x-status-badge :as="$backupUrl ? 'a' : 'span'" :href="$backupUrl"
                                        status="Backup enabled" type="success"
                                        :class="$backupUrl ? 'cursor-pointer underline' : null" />
                                @endif
                            </x-slot:labelSuffix>
                        </x-forms.input>
                        <x-forms.input id="hostPath" disabled />
                        <x-forms.input id="mountPath" required disabled />
                    </div>
                @endif
            @endcan
        @endif
    </form>
</div>
