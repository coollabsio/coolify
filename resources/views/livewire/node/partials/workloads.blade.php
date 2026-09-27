@php
    $moveTargetOptions = $node->cluster
        ? $node->cluster->nodes
            ->where('id', '!=', $node->id)
            ->sortBy('name')
            ->map(fn ($targetNode) => ['value' => $targetNode->uuid, 'label' => $targetNode->name])
            ->values()
            ->all()
        : [];
@endphp

<x-application.settings-section id="node-workloads-section" title="Workloads" flush
    helper="Applications that Coolify schedules on this Node. Deploy, restart, or move them to another Node in the cluster.">
    <x-slot:actions>
        <x-forms.button type="button" class="size-8! px-0!" wire:click="refreshWorkloads"
            title="Refresh workload state">
            <x-reicon name="refresh" class="size-3.5" />
        </x-forms.button>
    </x-slot:actions>

    @if ($node->workloads->isEmpty())
        <div class="p-4">
            <x-empty size="sm" title="No workloads on this Node"
                description="Workloads appear here after an application is deployed to this cluster." icon-name="layers" />
        </div>
    @else
        <div class="divide-y divide-neutral-200 dark:divide-white/[0.07]">
            @foreach ($node->workloads->sortBy('name') as $workload)
                @php
                    $revision = $workload->revisions->first();
                    $workloadState = data_get($workloadStates, $workload->uuid.'.status', 'Unknown');
                    $workloadProject = $workload->environment?->project;
                    $workloadUrl = $workloadProject
                        ? route('project.cluster-application.show', [
                            'project_uuid' => $workloadProject->uuid,
                            'environment_uuid' => $workload->environment->uuid,
                            'workload_uuid' => $workload->uuid,
                        ])
                        : null;
                    $internalHostname = $workload->internal_dns_name
                        ? $workload->internal_dns_name.'.default.coolify.internal'
                        : null;
                @endphp
                <div wire:key="node-workload-{{ $workload->uuid }}"
                    class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex min-w-0 flex-wrap items-center gap-2">
                            @if ($workloadUrl)
                                <a href="{{ $workloadUrl }}" {{ wireNavigate() }}
                                    class="truncate text-[13px] font-semibold text-black hover:underline dark:text-fg">
                                    {{ $workload->name }}
                                </a>
                            @else
                                <span class="truncate text-[13px] font-semibold text-black dark:text-fg">
                                    {{ $workload->name }}
                                </span>
                            @endif
                            <x-status-badge :status="$workloadState"
                                :type="data_get($workloadStates, $workload->uuid.'.type', 'neutral')" />
                        </div>
                        <p class="mt-1 truncate font-mono text-[11px] text-neutral-500 dark:text-fg-faint">
                            {{ $revision?->image ?? 'No revision is available.' }}
                        </p>
                        @if ($internalHostname)
                            <div class="mt-0.5 flex min-w-0 items-center gap-1">
                                <span class="truncate font-mono text-[11px] text-neutral-600 dark:text-fg-dim">
                                    {{ $internalHostname }}
                                </span>
                                <x-copy-button :value="$internalHostname" label="Copy internal hostname" />
                            </div>
                        @endif
                    </div>

                    @can('update', $node)
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($revision)
                                <x-forms.button isHighlighted wire:click="deployRevision('{{ $revision->uuid }}')">
                                    {{ $workloadState === 'Running' ? 'Redeploy' : 'Deploy' }}
                                </x-forms.button>
                                @if ($workloadState === 'Running')
                                    <x-forms.button class="size-8! px-0!" title="Restart" aria-label="Restart"
                                        wire:click="manageWorkload('restart', '{{ $revision->uuid }}')">
                                        <x-reicon name="restart" class="size-3.5" />
                                    </x-forms.button>
                                    <x-forms.button class="size-8! px-0!" title="Stop" aria-label="Stop"
                                        wire:click="manageWorkload('stop', '{{ $revision->uuid }}')">
                                        <x-reicon name="stop-circle" class="size-3.5" />
                                    </x-forms.button>
                                @elseif ($workloadState === 'Stopped')
                                    <x-forms.button class="size-8! px-0!" title="Start" aria-label="Start"
                                        wire:click="manageWorkload('start', '{{ $revision->uuid }}')">
                                        <x-reicon name="play-circle" class="size-3.5" />
                                    </x-forms.button>
                                @endif
                            @endif

                            @if ($revision && $moveTargetOptions !== [])
                                <x-modal-input title="Move to Node" :subtitle="$workload->name" :wireIgnore="false">
                                    <x-slot:content>
                                        <button type="button" class="icon-button" title="Move to another Node"
                                            aria-label="Move {{ $workload->name }} to another Node">
                                            <x-reicon name="arrow-right" class="size-3.5" />
                                        </button>
                                    </x-slot:content>
                                    <form wire:submit="moveWorkload('{{ $workload->uuid }}')" class="flex flex-col gap-4">
                                        <x-forms.listbox id="moveTargets.{{ $workload->uuid }}"
                                            htmlId="move-target-{{ $workload->uuid }}" label="Target Node"
                                            placeholder="Select a Node" :options="$moveTargetOptions" portal required />
                                        <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                            The workload keeps running here until it is ready on the target Node.
                                        </p>
                                        <div class="flex justify-end">
                                            <x-forms.button type="submit" isHighlighted
                                                wire:target="moveWorkload('{{ $workload->uuid }}')">
                                                Move workload
                                            </x-forms.button>
                                        </div>
                                    </form>
                                </x-modal-input>
                            @endif

                            <x-modal-input title="Edit internal DNS name" :subtitle="$workload->name" :wireIgnore="false">
                                <x-slot:content>
                                    <button type="button" class="icon-button" title="Edit internal DNS name"
                                        aria-label="Edit internal DNS name of {{ $workload->name }}">
                                        <x-reicon name="globe" class="size-3.5" />
                                    </button>
                                </x-slot:content>
                                <form wire:submit="saveWorkloadDnsName('{{ $workload->uuid }}')" class="flex flex-col gap-4">
                                    <div>
                                        <x-forms.input id="dnsNames.{{ $workload->uuid }}" label="Internal DNS name"
                                            maxlength="63" required />
                                        <p class="mt-1 font-mono text-[11px] text-neutral-500 dark:text-fg-faint">
                                            .default.coolify.internal
                                        </p>
                                    </div>
                                    <div class="flex justify-end">
                                        <x-forms.button type="submit" isHighlighted
                                            wire:target="saveWorkloadDnsName('{{ $workload->uuid }}')">
                                            Save
                                        </x-forms.button>
                                    </div>
                                </form>
                            </x-modal-input>

                            @if ($revision && in_array($workloadState, ['Running', 'Stopped', 'Outdated'], true))
                                <x-forms.button isError class="size-8! px-0!" title="Remove" aria-label="Remove"
                                    wire:confirm="Remove the {{ $workload->name }} container from this Node?"
                                    wire:click="manageWorkload('remove', '{{ $revision->uuid }}')">
                                    <x-reicon name="trash" class="size-3.5" />
                                </x-forms.button>
                            @endif
                        </div>
                    @endcan
                </div>
            @endforeach
        </div>
    @endif
</x-application.settings-section>

<x-application.settings-section id="node-activity-section" title="Recent activity" flush
    helper="The latest deploy, lifecycle, and move operations on this Node.">
    @if ($node->operations->isEmpty())
        <div class="p-4">
            <x-empty size="sm" title="No activity yet" description="Deployments and lifecycle actions appear here."
                icon-name="time-back" />
        </div>
    @else
        <div class="divide-y divide-neutral-200 dark:divide-white/[0.07]">
            @foreach ($node->operations as $operation)
                @php
                    $operationTarget = $operation->workload?->name ?? 'workload';
                    $operationLabel = match ($operation->command_type) {
                        'workload.deploy.v1' => 'Deploy '.$operationTarget,
                        'workload.lifecycle.v1' => str(data_get($operation->request, 'action', 'update'))->title().' '.$operationTarget,
                        'workload.move.v1' => 'Move '.$operationTarget,
                        'workload.resources.v1' => 'Update resources of '.$operationTarget,
                        default => str($operation->command_type)->beforeLast('.v')->replace('.', ' ')->ucfirst(),
                    };
                    $operationStatus = $operation->status->value;
                @endphp
                <div wire:key="node-operation-{{ $operation->uuid }}"
                    class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 text-[13px]">
                    <span class="min-w-0 truncate text-black dark:text-fg">{{ $operationLabel }}</span>
                    <div class="flex items-center gap-2">
                        <span class="text-[12px] text-neutral-500 dark:text-fg-faint"
                            title="{{ $operation->created_at->toIso8601String() }}">
                            {{ $operation->created_at->diffForHumans() }}
                        </span>
                        <x-status-badge :status="match ($operationStatus) {
                            'running' => 'Running',
                            'timed_out' => 'Timed out',
                            default => str($operationStatus)->title(),
                        }" :type="match ($operationStatus) {
                            'succeeded' => 'success',
                            'failed', 'timed_out' => 'error',
                            'uncertain' => 'warning',
                            default => 'neutral',
                        }" />
                        @if ($operationStatus === 'uncertain')
                            @can('update', $node)
                                <x-forms.button wire:click="retryOperation('{{ $operation->uuid }}')">
                                    Recover
                                </x-forms.button>
                            @endcan
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-application.settings-section>
