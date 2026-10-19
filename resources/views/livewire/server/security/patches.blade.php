<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Security | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <x-process-dialog @startupdate.window="processDialogOpen = true" closeWithX size="xl">
        <x-slot:title>Updating packages</x-slot:title>
        <x-slot:content>
            <div class="flex h-full min-h-0 flex-col">
                <livewire:activity-monitor header="Logs" fullHeight />
            </div>
        </x-slot:content>
    </x-process-dialog>

    @php
        $updates = data_get($result, 'updates', []);
        $totalUpdates = count($updates);
        $packageManager = data_get($result, 'package_manager');
        $checkedAt = $server->settings->patch_checked_at;
        $fullUpgradeOnly = in_array($packageManager, \App\Actions\Server\CheckUpdates::FULL_UPGRADE_ONLY, true);
    @endphp

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="security" activeSubMenu="patches" />

        <div class="application-settings-form flex w-full flex-col gap-6">
            <x-application.settings-section id="server-patching-overview-section" title="Server patching"
                helper="Discover and apply operating system package updates.">
                <x-slot:actions>
                    <x-status-badge status="Experimental" type="warning" />
                    @if (isDev())
                        <x-forms.button type="button" wire:click="sendTestEmail">
                            Send test email
                        </x-forms.button>
                    @endif
                    @can('update', $server)
                        <x-forms.button type="button" wire:click="checkForUpdates" :disabled="$checking">
                            <x-reicon name="refresh" class="size-3.5" />
                            Check for updates
                        </x-forms.button>
                    @endcan
                </x-slot:actions>

                <div class="flex flex-wrap items-center gap-2 text-sm text-neutral-600 dark:text-fg-dim">
                    <span>
                        Last checked:
                        @if ($checkedAt)
                            <span title="{{ $checkedAt->toDateTimeString() }}">{{ $checkedAt->diffForHumans() }}</span>
                        @else
                            never
                        @endif
                    </span>
                    @if ($checkedAt && ! $error)
                        @if ($totalUpdates === 0)
                            <x-status-badge status="Up to date" type="success" />
                        @else
                            <x-status-badge :status="$totalUpdates . ' ' . Str::plural('update', $totalUpdates)" type="warning" />
                        @endif
                    @endif
                </div>

                <x-callout type="info" title="Supported package managers">
                    Automated package discovery currently supports apk, apt, dnf, pacman, and zypper. Status
                    notifications can be managed from
                    <a class="font-medium underline" href="{{ route('notifications.email') }}"
                        {{ wireNavigate() }}>notification settings</a>.
                </x-callout>
            </x-application.settings-section>

            @if ($checking)
                <div wire:poll.10s="loadResult">
                    <x-application.settings-section title="Checking for updates"
                        helper="Package discovery runs in the background and can take several minutes.">
                        <div class="flex items-center gap-3 py-4 text-sm text-neutral-600 dark:text-fg-dim">
                            <x-loading />
                            Inspecting installed packages…
                        </div>
                    </x-application.settings-section>
                </div>
            @elseif ($error)
                <x-application.settings-section title="Package updates"
                    helper="Available operating system updates for this server.">
                    <x-callout type="danger" title="Could not check for updates">
                        {{ $error }}
                    </x-callout>
                </x-application.settings-section>
            @elseif ($checkedAt && $totalUpdates === 0)
                <x-application.settings-section title="Package updates"
                    helper="Available operating system updates for this server.">
                    <x-empty size="sm" title="Server is up to date"
                        description="No package updates are currently available." icon-name="check-circle" />
                </x-application.settings-section>
            @elseif ($totalUpdates > 0)
                <x-application.settings-section id="server-package-updates-section" title="Package updates"
                    helper="{{ $totalUpdates }} {{ Str::plural('update', $totalUpdates) }} available."
                    flush>
                    @can('update', $server)
                        <x-slot:actions>
                            <x-modal-confirmation title="Confirm package update?" buttonTitle="Update all packages"
                                isHighlightedButton submitAction="updateAllPackages" :actions="[
                                    'All packages will be updated to their latest available versions.',
                                    'Docker or kernel updates may restart running containers.',
                                ]"
                                confirmationText="Update All Packages"
                                confirmationLabel="Confirm by entering the text below"
                                shortConfirmationLabel="Confirmation" :confirmWithPassword="false"
                                step2ButtonText="Update All Packages" />
                        </x-slot:actions>
                    @endcan

                    @if ($fullUpgradeOnly)
                        <div class="px-4 pt-4">
                            <x-callout type="info" title="Full system upgrade only">
                                Arch Linux does not support partial upgrades, so packages cannot be updated one by one.
                            </x-callout>
                        </div>
                    @endif

                    <div class="data-table">
                        <div class="data-table-header package-updates-table-grid">
                            <span>Package</span>
                            <span>New version</span>
                            <span class="text-right">Action</span>
                        </div>
                        @foreach ($updates as $update)
                            <div wire:key="package-update-{{ data_get($update, 'package') }}-{{ data_get($update, 'architecture') }}"
                                class="data-table-row package-updates-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]">
                                <div class="flex min-w-0 items-center gap-2">
                                    <span class="min-w-0 truncate font-mono text-[12px] text-neutral-950 dark:text-fg">
                                        {{ data_get($update, 'package') }}
                                    </span>
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate font-mono text-[12px] text-neutral-700 dark:text-fg-dim">
                                        {{ data_get($update, 'new_version') }}
                                    </p>
                                    @if (data_get($update, 'current_version') && data_get($update, 'current_version') !== 'unknown')
                                        <p class="mt-0.5 truncate text-[10px] text-neutral-500 dark:text-fg-faint">
                                            Current: {{ data_get($update, 'current_version') }}
                                        </p>
                                    @endif
                                </div>
                                <div class="flex justify-end">
                                    @if (! $fullUpgradeOnly)
                                        @can('update', $server)
                                            <x-forms.button type="button"
                                                wire:click="updatePackage({{ Js::from(data_get($update, 'package')) }})">
                                                Update
                                            </x-forms.button>
                                        @endcan
                                    @endif
                                </div>
                            </div>
                        @endforeach
                        <div
                            class="flex min-h-11 items-center border-t border-neutral-200 px-4 text-[11px] text-neutral-500 dark:border-white/[0.08] dark:text-fg-faint">
                            {{ $totalUpdates }} {{ Str::plural('package update', $totalUpdates) }}
                        </div>
                    </div>
                </x-application.settings-section>
            @endif

            <form wire:submit="savePatchCheckFrequency">
                <x-application.settings-section id="server-patching-schedule-section" title="Schedule"
                    helper="Control when Coolify checks this server for package updates.">
                    <x-slot:actions>
                        @can('update', $server)
                            <x-forms.button type="submit">Save</x-forms.button>
                        @endcan
                    </x-slot:actions>
                    <x-forms.input canGate="update" :canResource="$server" id="patchCheckFrequency"
                        label="Check frequency" placeholder="weekly (default, on Sunday)"
                        helper="Cron expression or preset such as daily, weekly, or monthly. Leave empty for the default: weekly, at a fixed time on Sunday. Coolify notifies your team when updates are available and when a check starts to fail." />
                </x-application.settings-section>
            </form>
        </div>
    </div>
</div>
