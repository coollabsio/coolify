<x-application.settings-section id="node-workloads-section" title="Applications" flush
    helper="Applications that Coolify schedules on this server. Actions here apply to this server only.">
    <x-slot:actions>
        <x-forms.button type="button" wire:click="refreshWorkloads" title="Refresh application state">
            <x-reicon name="refresh" class="size-3.5" />
            Refresh
        </x-forms.button>
    </x-slot:actions>

    @if ($workloadRows === [])
        <div class="p-4">
            <x-empty size="sm" title="No applications on this server"
                description="Applications appear here after they are deployed to this cluster." icon-name="layers" />
        </div>
    @else
        <div class="divide-y divide-neutral-200 dark:divide-white/[0.07]">
            @foreach ($workloadRows as $row)
                @php
                    $workload = $row['workload'];
                    $revision = $row['revision'];
                    $lastActivity = $row['lastActivity'];
                    $triggerPrefix = 'node-workload-'.$workload->uuid;
                    $activityToneClass = match ($lastActivity['tone'] ?? null) {
                        'error' => 'text-error',
                        'warning' => 'text-warning-700 dark:text-warning',
                        'active' => 'text-neutral-700 dark:text-fg-dim',
                        default => 'text-neutral-500 dark:text-fg-faint',
                    };
                @endphp
                <div wire:key="node-workload-{{ $workload->uuid }}" data-node-workload-row
                    class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3">
                    <div class="relative w-full min-w-0 sm:w-auto sm:flex-1">
                        <div class="flex min-w-0 items-center gap-2">
                            @if ($row['href'])
                                <a href="{{ $row['href'] }}" {{ wireNavigate() }}
                                    class="min-w-0 truncate text-[13px] font-semibold text-black hover:underline dark:text-fg">
                                    {{ $workload->name }}
                                </a>
                            @else
                                <span class="min-w-0 truncate text-[13px] font-semibold text-black dark:text-fg">
                                    {{ $workload->name }}
                                </span>
                            @endif
                            <x-status-badge class="shrink-0" :status="$row['status']" :type="$row['statusType']" />
                            {{-- Below sm the panel spans the row like on the application page, above it sits under the trigger. --}}
                            <div class="flex shrink-0 sm:relative">
                                <x-cluster-applications.links :workload="$workload" compact />
                            </div>
                        </div>
                        <p data-node-workload-activity class="mt-1 truncate text-[12px] leading-4 {{ $activityToneClass }}">
                            @if ($lastActivity)
                                @if ($lastActivity['href'])
                                    <a href="{{ $lastActivity['href'] }}" {{ wireNavigate() }}
                                        class="hover:underline" title="{{ $lastActivity['timestamp'] }}">
                                        {{ $lastActivity['text'] }} {{ $lastActivity['time'] }}
                                    </a>
                                @else
                                    <span title="{{ $lastActivity['timestamp'] }}">
                                        {{ $lastActivity['text'] }} {{ $lastActivity['time'] }}
                                    </span>
                                @endif
                            @else
                                No activity on this server yet
                            @endif
                        </p>
                    </div>

                    @can('update', $node)
                        @if ($revision)
                            <x-cluster-applications.deployment-actions id="node-workload-actions-{{ $workload->uuid }}"
                                :is-running="$row['isRunning']" :container-present="$row['containerPresent']"
                                deploy-action="deployRevision('{{ $revision->uuid }}')" :trigger-prefix="$triggerPrefix"
                                class="shrink-0">
                                <div class="my-1 border-t border-neutral-200 dark:border-white/[0.08]" role="separator"></div>
                                @if ($moveTargetOptions !== [])
                                    <button type="button" class="listbox-option justify-start! gap-2.5!"
                                        @click="open = false; document.getElementById('{{ $triggerPrefix }}-move-trigger')?.click()"
                                        role="menuitem">
                                        <x-reicon name="transfer" class="size-3.5 opacity-70" />
                                        Move to another server…
                                    </button>
                                @endif
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    @click="open = false; document.getElementById('{{ $triggerPrefix }}-dns-trigger')?.click()"
                                    role="menuitem">
                                    <x-reicon name="globe" class="size-3.5 opacity-70" />
                                    Edit internal DNS name…
                                </button>
                            </x-cluster-applications.deployment-actions>

                            {{-- Keyed by state: the wire:ignore confirmations must render again when the state changes.
                                 Each submit action carries the workload uuid, resolved and authorized server-side. --}}
                            <div class="hidden" aria-hidden="true"
                                wire:key="node-workload-confirmations-{{ $workload->uuid }}-{{ $row['isRunning'] ? 'running' : 'exited' }}">
                                <x-modal-confirmation
                                    title="{{ $row['isRunning'] ? 'Confirm Application Stopping?' : 'Confirm Container Removal?' }}"
                                    buttonTitle="{{ $row['isRunning'] ? 'Stop' : 'Remove container' }}"
                                    submitAction="manageWorkload({{ $row['isRunning'] ? 'stop' : 'remove' }}, {{ $workload->uuid }})"
                                    :actions="$row['isRunning']
                                        ? ['This application will be stopped on '.$node->name.'.', 'Other servers of the application are not affected.']
                                        : ['The exited application container will be removed from '.$node->name.'.', 'Deploy the application to create it again.']"
                                    :confirmWithText="false" :confirmWithPassword="false"
                                    step1ButtonText="Continue" step2ButtonText="Confirm">
                                    <x-slot:trigger>
                                        <button id="{{ $triggerPrefix }}-stop-trigger" type="button">Stop</button>
                                    </x-slot:trigger>
                                </x-modal-confirmation>
                                <x-modal-confirmation title="Confirm Application Restart?" buttonTitle="Restart"
                                    submitAction="manageWorkload(restart, {{ $workload->uuid }})" :actions="[
                                        'This application will be restarted on '.$node->name.' without rebuilding.',
                                    ]" :confirmWithText="false" :confirmWithPassword="false"
                                    step2ButtonText="Confirm">
                                    <x-slot:trigger>
                                        <button id="{{ $triggerPrefix }}-restart-trigger" type="button">Restart</button>
                                    </x-slot:trigger>
                                </x-modal-confirmation>

                                @if ($moveTargetOptions !== [])
                                    <x-modal-input title="Move to another server" :subtitle="$workload->name" :wireIgnore="false">
                                        <x-slot:content>
                                            <button id="{{ $triggerPrefix }}-move-trigger" type="button">Move</button>
                                        </x-slot:content>
                                        <form wire:submit="moveWorkload('{{ $workload->uuid }}')" class="flex flex-col gap-4">
                                            <x-forms.listbox id="moveTargets.{{ $workload->uuid }}"
                                                htmlId="move-target-{{ $workload->uuid }}" label="Target server"
                                                placeholder="Select a server" :options="$moveTargetOptions" portal required />
                                            <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                                The application keeps running here until it is ready on the target server.
                                            </p>
                                            <div class="flex justify-end">
                                                <x-forms.button type="submit" isHighlighted
                                                    wire:target="moveWorkload('{{ $workload->uuid }}')">
                                                    Move application
                                                </x-forms.button>
                                            </div>
                                        </form>
                                    </x-modal-input>
                                @endif

                                <x-modal-input title="Edit internal DNS name" :subtitle="$workload->name" :wireIgnore="false">
                                    <x-slot:content>
                                        <button id="{{ $triggerPrefix }}-dns-trigger" type="button">Edit internal DNS name</button>
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
                            </div>
                        @endif
                    @endcan
                </div>
            @endforeach
        </div>
    @endif
</x-application.settings-section>
