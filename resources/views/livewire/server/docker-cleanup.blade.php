<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Docker Cleanup | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-8 grid w-full max-w-[1180px] min-w-0 gap-8 xl:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
        <x-server.sidebar :server="$server" activeMenu="docker-cleanup" />

        <div class="application-settings-form flex w-full flex-col gap-6">
            <form wire:submit="submit" class="contents">
                <x-unsaved-bar action="submit" />

                <x-application.settings-section id="docker-cleanup-overview-section" title="Docker cleanup"
                    helper="Remove unused Docker data and keep disk usage under control.">
                    <x-slot:actions>
                        @can('update', $server)
                            <x-modal-confirmation title="Confirm Docker Cleanup?"
                                buttonTitle="Run cleanup" isHighlightedButton submitAction="manualCleanup"
                                :actions="[
                                    'Deletes stopped containers managed by Coolify.',
                                    'Deletes unused images and clears the build cache.',
                                    'Removes old Coolify helper images.',
                                    'May delete unused volumes or networks when those options are enabled.',
                                ]" :confirmWithText="false" :confirmWithPassword="false"
                                step2ButtonText="Run Docker Cleanup" />
                        @endcan
                    </x-slot:actions>

                    @if (!isCloud() && $this->isCleanupStale)
                        <x-callout type="warning" title="Docker cleanup may be stalled">
                            The last cleanup ran {{ $this->lastExecutionTime ?? 'at an unknown time' }}.
                            @if (!$this->isSchedulerHealthy)
                                The scheduled job manager also appears inactive.
                            @endif
                            Run
                            <code class="rounded bg-black/10 px-1 dark:bg-white/10">php artisan cleanup:redis --clear-locks</code>
                            on the Coolify instance to clear stale locks.
                        </x-callout>
                    @else
                        <div class="flex items-start gap-3">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
                                <x-reicon name="storages" class="size-4" />
                            </div>
                            <div>
                                <p class="text-sm font-medium text-neutral-950 dark:text-fg">Scheduled maintenance</p>
                                <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                                    Cleanup runs automatically using the schedule and threshold configured below.
                                </p>
                            </div>
                        </div>
                    @endif
                </x-application.settings-section>

                <x-application.settings-section id="docker-cleanup-configuration-section"
                    title="Cleanup configuration"
                    helper="Choose when cleanup runs and whether it should wait for a disk threshold.">
                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.input canGate="update" :canResource="$server" placeholder="0 0 * * *"
                            id="dockerCleanupFrequency" label="Cleanup frequency" required
                            helper="Cron expression or preset such as hourly, daily, weekly, monthly, or yearly." />
                        @if (!$forceDockerCleanup)
                            <x-forms.input canGate="update" :canResource="$server" id="dockerCleanupThreshold"
                                type="number" min="1" max="99" label="Disk threshold" required
                                helper="Run cleanup after disk usage exceeds this percentage." />
                        @endif
                        <x-forms.listbox id="forceDockerCleanup" label="Cleanup trigger"
                            helper="Forced cleanup runs on every schedule without waiting for the threshold."
                            onChange="instantSave" :options="[
                                ['value' => false, 'label' => 'Only above disk threshold'],
                                ['value' => true, 'label' => 'Run on every schedule'],
                            ]" />
                    </div>
                </x-application.settings-section>

                <x-application.settings-section id="docker-cleanup-advanced-section" title="Advanced cleanup"
                    helper="Control destructive cleanup behavior and application image retention.">
                    <x-callout type="warning" title="These options can remove recoverable data">
                        Unused volumes may contain data from stopped containers, while removing retained images
                        disables rollback to older application versions.
                    </x-callout>

                    <div class="mt-4 grid gap-4 lg:grid-cols-3">
                        <x-forms.listbox id="deleteUnusedVolumes" label="Unused volumes"
                            helper="Permanently remove volumes not attached to running containers."
                            onChange="instantSave" :options="[
                                ['value' => false, 'label' => 'Keep unused volumes'],
                                ['value' => true, 'label' => 'Delete unused volumes'],
                            ]" />
                        <x-forms.listbox id="deleteUnusedNetworks" label="Unused networks"
                            helper="Remove Docker networks not attached to running containers."
                            onChange="instantSave" :options="[
                                ['value' => false, 'label' => 'Keep unused networks'],
                                ['value' => true, 'label' => 'Delete unused networks'],
                            ]" />
                        <x-forms.listbox id="disableApplicationImageRetention" label="Application images"
                            helper="Keeping retained images allows application rollbacks."
                            onChange="instantSave" :options="[
                                ['value' => false, 'label' => 'Keep retained images'],
                                ['value' => true, 'label' => 'Delete all old images'],
                            ]" />
                    </div>
                </x-application.settings-section>
            </form>

            <x-application.settings-section id="docker-cleanup-executions-section" title="Recent executions"
                helper="Review cleanup status, duration, and command output." flush>
                <livewire:server.docker-cleanup-executions :server="$server" />
            </x-application.settings-section>
        </div>
    </div>
</div>
