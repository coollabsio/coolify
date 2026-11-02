@php
    $hasVolumes = $volumes !== [];
    $gridClass = 'grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,8rem)_7rem_6rem]';
    $statusTypes = ['ready' => 'success', 'missing' => 'error', 'not created' => 'neutral', 'unknown' => 'neutral'];
@endphp

<div class="flex flex-col gap-6">
    <x-application.settings-section id="cluster-application-volumes" title="Volumes" :flush="$hasVolumes"
        helper="A volume keeps its data when the container is replaced. It lives on one server, and the application can run only there. Moving the application copies the data to the new server.">
        <x-slot:actions>
            @can('update', $workload)
                @if (! $multipleServers)
                    <x-modal-input title="Add volume" :closeOutside="false" :wireIgnore="false">
                        <x-slot:content>
                            <button type="button" class="button button-highlighted">
                                <x-reicon name="plus" class="size-3.5" />
                                Add volume
                            </button>
                        </x-slot:content>
                        <form wire:submit="addVolume" class="application-settings-form flex flex-col gap-4">
                            <x-forms.input id="newVolumeName" label="Name" placeholder="data" required
                                helper="A label for this volume in Coolify." />
                            <x-forms.input id="newVolumeMountPath" label="Mount path" placeholder="/data" required
                                helper="An absolute path inside the container. Redeploy the application to mount the volume." />
                            <div class="flex justify-end pt-2">
                                <x-forms.button type="submit" isHighlighted>Save</x-forms.button>
                            </div>
                        </form>
                    </x-modal-input>
                @endif
            @endcan
        </x-slot:actions>

        @if ($multipleServers)
            <div class="px-4 pt-4">
                <x-callout type="warning" title="The application runs on more than one server">
                    A volume lives on one server. Run the application on one server to add a volume.
                </x-callout>
            </div>
        @endif

        @if ($hasVolumes)
            <div class="data-table-header {{ $gridClass }}">
                <span>Name</span>
                <span>Mount path</span>
                <span>Server</span>
                <span>Status</span>
                <span class="text-right">Actions</span>
            </div>
            <div class="data-table w-full">
                @foreach ($volumes as $volume)
                    <div wire:key="cluster-application-volume-{{ $volume['uuid'] }}" class="env-table-item">
                        <div class="data-table-row {{ $gridClass }} py-2!">
                            <span class="min-w-0 truncate text-[13px] text-black dark:text-fg" title="{{ $volume['name'] }}">{{ $volume['name'] }}</span>
                            <span class="min-w-0 truncate font-mono text-[12px]" title="{{ $volume['mountPath'] }}">{{ $volume['mountPath'] }}</span>
                            <span class="min-w-0 truncate text-[12px]">{{ $volume['server'] ?? '-' }}</span>
                            <div class="justify-self-start">
                                @if ($volume['detached'])
                                    <x-status-badge status="Removed" type="warning" />
                                @elseif ($volume['receiving'])
                                    <x-status-badge status="Moving" type="warning" pulse />
                                @else
                                    <x-status-badge :status="str($volume['status'])->ucfirst()" :type="$statusTypes[$volume['status']] ?? 'neutral'" />
                                @endif
                            </div>
                            <div class="flex items-center justify-end gap-1">
                                @can('update', $workload)
                                    @if ($volume['detached'])
                                        <x-modal-confirmation class="!w-auto shrink-0" title="Delete volume data?" buttonTitle="Delete data"
                                            isErrorButton canGate="update" :canResource="$workload"
                                            submitAction="deleteVolumeData('{{ $volume['uuid'] }}')" :actions="[
                                                'The data of this volume is permanently deleted on '.($volume['server'] ?? 'its server').'.',
                                                'Retained copies on other servers are deleted too.',
                                            ]" :confirmWithPassword="false" confirmationText="{{ $volume['name'] }}"
                                            confirmationLabel="Enter the volume name to confirm" shortConfirmationLabel="Volume name"
                                            step2ButtonText="Delete data">
                                            <x-slot:trigger>
                                                <button type="button" class="button text-red-500! dark:text-red-400!" title="Delete the data of {{ $volume['name'] }}">
                                                    Delete data
                                                </button>
                                            </x-slot:trigger>
                                        </x-modal-confirmation>
                                    @else
                                        <x-modal-confirmation class="!w-auto shrink-0" title="Remove volume?" buttonTitle="Remove"
                                            isErrorButton canGate="update" :canResource="$workload"
                                            submitAction="removeVolume('{{ $volume['uuid'] }}')" :actions="[
                                                'The next deployment no longer mounts this volume.',
                                                'Its data stays on the server until you delete it.',
                                            ]" :confirmWithPassword="false" :confirmWithText="false"
                                            step2ButtonText="Remove volume">
                                            <x-slot:trigger>
                                                <button type="button"
                                                    class="icon-button shrink-0 text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300"
                                                    title="Remove volume" aria-label="Remove {{ $volume['name'] }}">
                                                    <x-reicon name="trash" class="size-3.5" />
                                                </button>
                                            </x-slot:trigger>
                                        </x-modal-confirmation>
                                    @endif
                                @endcan
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <x-empty size="sm" title="No volumes"
                description="Add a volume for data that must survive a redeploy, for example a database directory." icon-name="storages" />
        @endif
    </x-application.settings-section>

    @if ($retainedCopies !== [])
        <x-application.settings-section id="cluster-application-retained-copies" title="Retained copies" flush
            helper="The data that stayed on the previous server after a move. Coolify deletes it automatically after the date shown.">
            <div class="data-table-header grid-cols-[minmax(0,1fr)_minmax(0,8rem)_9rem_7rem]">
                <span>Volume</span>
                <span>Server</span>
                <span>Deleted after</span>
                <span class="text-right">Actions</span>
            </div>
            <div class="data-table w-full">
                @foreach ($retainedCopies as $copy)
                    <div wire:key="cluster-application-volume-copy-{{ $copy['uuid'] }}" class="env-table-item">
                        <div class="data-table-row grid-cols-[minmax(0,1fr)_minmax(0,8rem)_9rem_7rem] py-2!">
                            <span class="min-w-0 truncate text-[13px] text-black dark:text-fg" title="{{ $copy['mountPath'] }}">{{ $copy['volume'] }}</span>
                            <span class="min-w-0 truncate text-[12px]">{{ $copy['server'] ?? '-' }}</span>
                            <span class="text-[12px]" title="{{ $copy['deleteAfter']?->toIso8601String() }}">{{ $copy['deleteAfter']?->diffForHumans() ?? '-' }}</span>
                            <div class="flex items-center justify-end">
                                @can('update', $workload)
                                    <x-modal-confirmation class="!w-auto shrink-0" title="Delete retained copy?" buttonTitle="Delete now"
                                        isErrorButton canGate="update" :canResource="$workload"
                                        submitAction="deleteVolumeCopy('{{ $copy['uuid'] }}')" :actions="[
                                            'The copy of this volume on '.($copy['server'] ?? 'the previous server').' is permanently deleted.',
                                            'The volume that the application uses is not changed.',
                                        ]" :confirmWithPassword="false" :confirmWithText="false"
                                        step2ButtonText="Delete copy">
                                        <x-slot:trigger>
                                            <button type="button"
                                                class="icon-button shrink-0 text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300"
                                                title="Delete retained copy" aria-label="Delete the retained copy of {{ $copy['volume'] }}">
                                                <x-reicon name="trash" class="size-3.5" />
                                            </button>
                                        </x-slot:trigger>
                                    </x-modal-confirmation>
                                @endcan
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-application.settings-section>
    @endif
</div>
