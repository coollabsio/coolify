<div class="flex flex-col gap-6">
    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] leading-5 text-amber-800 dark:border-warning/15 dark:bg-warning/[0.07] dark:text-amber-300/90">
        The volumes of a stack come from its Compose file. Edit the Compose file to change them.
    </div>
    @foreach ($services as $service)
        <x-application.settings-section :id="'storage-service-'.$service['uuid']" :title="str($service['service'])->headline()" wire:key="cluster-stack-storage-{{ $service['uuid'] }}">
            <x-slot:actions>
                <a class="button" {{ wireNavigate() }} href="{{ $service['storageHref'] }}">Manage storage</a>
            </x-slot:actions>
            @forelse ($service['mounts'] as $mount)
                <p class="truncate text-xs text-neutral-500 dark:text-fg-dim">
                    <span class="font-mono text-black dark:text-fg">{{ $mount['name'] }}</span>: <span class="font-mono">{{ $mount['path'] }}</span>{{ $mount['readOnly'] ? ' (read-only)' : '' }}{{ $mount['shared'] ? ' (shared)' : '' }}
                </p>
            @empty
                <p class="text-xs text-neutral-500 dark:text-fg-dim">No persistent storage.</p>
            @endforelse
        </x-application.settings-section>
    @endforeach

    @if ($volumes !== [])
        @php($statusTypes = ['ready' => 'success', 'missing' => 'error', 'not created' => 'neutral', 'unknown' => 'neutral'])
        <x-application.settings-section id="cluster-stack-volumes" title="Shared volumes"
            helper="Volumes that several services of this stack mount. A shared volume lives on one server: every service that mounts it runs there, and moving one of them moves all of them with the data.">
            <div class="flex flex-col divide-y divide-neutral-200 dark:divide-white/[0.07]">
                @foreach ($volumes as $volume)
                    <div wire:key="cluster-stack-volume-{{ $volume['uuid'] }}"
                        class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <h4 class="truncate text-sm font-semibold text-black dark:text-fg">{{ $volume['name'] }}</h4>
                            @forelse ($volume['mounts'] as $mount)
                                <p class="mt-1 truncate text-xs text-neutral-500 dark:text-fg-dim">
                                    {{ $mount['service'] }}: <span class="font-mono">{{ $mount['path'] }}</span>{{ $mount['readOnly'] ? ' (read-only)' : '' }}
                                </p>
                            @empty
                                <p class="mt-1 text-xs text-neutral-500 dark:text-fg-dim">No service mounts this volume.</p>
                            @endforelse
                        </div>
                        <div class="flex shrink-0 items-center gap-3 text-xs text-neutral-500 dark:text-fg-dim">
                            @if ($volume['server'])
                                <span>{{ $volume['server'] }}</span>
                            @endif
                            @if ($volume['receiving'])
                                <x-status-badge status="Moving" type="warning" pulse />
                            @else
                                <x-status-badge :status="str($volume['status'])->ucfirst()" :type="$statusTypes[$volume['status']] ?? 'neutral'" />
                            @endif
                            @if ($volume['mounts'] === [])
                                @can('update', $stack)
                                    <x-modal-confirmation class="!w-auto shrink-0" title="Delete volume data?" buttonTitle="Delete data"
                                        isErrorButton canGate="update" :canResource="$stack"
                                        submitAction="deleteVolume('{{ $volume['uuid'] }}')" :actions="[
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
                                @endcan
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-application.settings-section>
    @endif
</div>
