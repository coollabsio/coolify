<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Patching | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <x-process-dialog @startupdate.window="processDialogOpen = true" closeWithX size="xl">
        <x-slot:title>Package Update Logs</x-slot:title>
        <x-slot:content>
            <div class="flex h-full min-h-0 flex-col">
                <livewire:activity-monitor fullHeight />
            </div>
        </x-slot:content>
    </x-process-dialog>

    @php
        $totalUpdates = count($updates);
        $heldUpdates = collect(data_get($result, 'held_updates', []))->sortBy('package')->values()->all();
        $packageManager = data_get($result, 'package_manager');
        $checkedAt = $server->settings->patch_checked_at;
        $fullUpgradeOnly = in_array($packageManager, \App\Actions\Server\CheckUpdates::FULL_UPGRADE_ONLY, true);
        $securityUpdates = data_get($result, 'security_updates');
        $rebootRequired = data_get($result, 'reboot_required') === true;
        $riskyUpdates = collect($updates)->filter(fn ($update) => filled(data_get($update, 'risk')))->count();
    @endphp


    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="security" activeSubMenu="patches" />

    <div class="application-settings-form flex w-full min-w-0 flex-col gap-6">
        @if (! $server->isFunctional())
            <x-empty size="sm" icon-name="shield-alert" title="Coolify cannot reach {{ $server->name }}."
                description="Patching runs commands on the server. Validate the server first." />
        @else
        <form wire:submit="submit">
            <x-unsaved-bar action="submit" />

            <x-application.settings-section id="server-patching-overview-section" title="Server patching"
                helper="Discover and apply operating system package updates.">
                <x-slot:badges>
                    @if ($checking)
                        <x-status-badge status="Checking…" type="neutral" spinning />
                    @elseif ($error)
                        <x-status-badge status="Check failed" type="error" />
                    @elseif ($checkedAt)
                        @if ($totalUpdates === 0)
                            <x-status-badge status="Up to date" type="success" />
                        @elseif ($securityUpdates > 0)
                            <x-status-badge :status="$securityUpdates . ' ' . Str::plural('security update', $securityUpdates)" type="error" />
                        @else
                            <x-status-badge :status="$totalUpdates . ' ' . Str::plural('update', $totalUpdates)" type="warning" />
                        @endif
                        @if ($rebootRequired)
                            <x-status-badge status="Reboot required" type="warning" />
                        @endif
                    @else
                        <x-status-badge status="Not checked yet" type="neutral" />
                    @endif
                </x-slot:badges>
                <x-slot:actions>
                    <x-beta-badge />
                    @can('update', $server)
                        <x-split-action id="server-patching-actions">
                            <x-slot:main @click="open = ! open" aria-haspopup="menu">
                                Actions
                            </x-slot:main>
                            <button type="button" class="listbox-option justify-start! gap-2.5!" role="menuitem"
                                @click="open = false" wire:click="checkForUpdates" @disabled($checking)>
                                <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                Check for updates
                            </button>
                            @if (\App\Actions\Server\RemoveUnusedPackages::supports($packageManager))
                                <button type="button" class="listbox-option justify-start! gap-2.5!" role="menuitem"
                                    @click="open = false; document.getElementById('server-patching-remove-unused-trigger')?.click()">
                                    <x-reicon name="trash" class="size-3.5 opacity-70" />
                                    Remove unused packages
                                </button>
                            @endif
                            @if ($rebootRequired)
                                <button type="button" class="listbox-option justify-start! gap-2.5! text-red-500!" role="menuitem"
                                    @click="open = false; document.getElementById('server-patching-reboot-trigger')?.click()">
                                    <x-reicon name="restart" class="size-3.5 opacity-70" />
                                    Reboot server
                                </button>
                            @endif
                            @if (isDev())
                                <button type="button" class="listbox-option justify-start! gap-2.5!" role="menuitem"
                                    @click="open = false" wire:click="sendTestEmail">
                                    <x-reicon name="mail" class="size-3.5 opacity-70" />
                                    Send test email
                                </button>
                            @endif
                        </x-split-action>
                        <div class="hidden" aria-hidden="true">
                            <x-server.remove-unused-packages-button :packageManager="$packageManager"
                                triggerId="server-patching-remove-unused-trigger" />
                            @if ($rebootRequired)
                                <x-modal-confirmation title="Reboot server?" buttonTitle="Reboot server"
                                    submitAction="rebootServer"
                                    warningMessage="The containers on this server stop until the server is back." :actions="[
                                        'The server reboots in a few seconds.',
                                        'All containers on this server stop, and the containers with a restart policy start again after the reboot.',
                                        'The server is not reachable for a few minutes.',
                                    ]"
                                    confirmationText="{{ $server->name }}"
                                    confirmationLabel="Enter the server name to confirm"
                                    shortConfirmationLabel="Server name" :confirmWithPassword="false"
                                    step2ButtonText="Reboot server">
                                    <x-slot:trigger>
                                        <button id="server-patching-reboot-trigger" type="button">Reboot server</button>
                                    </x-slot:trigger>
                                </x-modal-confirmation>
                            @endif
                        </div>
                    @endcan
                </x-slot:actions>

                <div class="flex flex-col gap-4">

                    <div class="flex flex-wrap items-center gap-2 text-sm text-neutral-600 dark:text-fg-dim">
                        <span>
                            Last checked:
                            @if ($checkedAt)
                                <span title="{{ $checkedAt->toDateTimeString() }}">{{ $checkedAt->diffForHumans() }}</span>
                            @else
                                never
                            @endif
                        </span>
                    </div>

                    @php
                        $autoUpdatesSupported = \App\Actions\Server\ConfigureAutomaticSecurityUpdates::supportFor($packageManager);
                    @endphp
                    <div @class(['grid gap-4', 'lg:grid-cols-3' => $autoUpdatesSupported])>
                        <x-forms.input canGate="update" :canResource="$server" id="patchCheckFrequency"
                            label="Check frequency" placeholder="weekly (default, on Sunday)"
                            helper="Cron expression or preset such as daily, weekly, or monthly. Leave empty for the default: weekly, at a fixed time on Sunday. Coolify notifies your team when updates are available and when a check starts to fail." />
                        @if ($autoUpdatesSupported)
                            <div class="w-full">
                            <div class="mb-1.5 flex h-4 w-full items-center gap-1.5">
                                <label class="mb-0! flex items-center gap-1 text-sm font-medium leading-4">Automatic security updates</label>
                                <x-helper helper="{{ $packageManager === 'apt' ? 'Uses unattended-upgrades for the security origins only.' : 'Uses dnf-automatic with upgrade_type = security.' }} Turning it off removes the Coolify configuration and keeps the operating system default." />
                            </div>
                            <div class="input flex items-center justify-between gap-2 pr-1!">
                                <span class="flex min-w-0 items-center gap-2 truncate text-neutral-700 dark:text-fg-dim">
                                    <span @class(['size-1.5 shrink-0 rounded-full', 'bg-emerald-500' => $autoSecurityUpdates, 'bg-neutral-400 dark:bg-neutral-500' => ! $autoSecurityUpdates])></span>
                                    {{ $autoSecurityUpdates ? 'On, every day' : 'Off' }}
                                </span>
                                @can('update', $server)
                                    <div class="flex shrink-0" wire:key="auto-security-updates-{{ $autoSecurityUpdates ? 'on' : 'off' }}">
                                        @if ($autoSecurityUpdates)
                                            <x-modal-confirmation title="Turn off automatic security updates?"
                                                submitAction="toggleAutomaticSecurityUpdates"
                                                warningMessage="Security fixes are then not installed automatically by Coolify."
                                                temporaryDisableTwoStepConfirmation :confirmWithText="false"
                                                :confirmWithPassword="false" :actions="[
                                                    'Coolify removes its configuration from the server.',
                                                    'The server uses the default of the operating system again. Check it if you want no automatic updates at all.',
                                                ]"
                                                step2ButtonText="Turn off">
                                                <x-slot:trigger>
                                                    <button type="button"
                                                        class="h-6 shrink-0 rounded-md border border-neutral-200 bg-white px-2 text-[12px] font-medium text-neutral-800 hover:bg-neutral-50 dark:border-white/[0.1] dark:bg-white/[0.06] dark:text-fg dark:hover:bg-white/[0.1]">
                                                        Turn off
                                                    </button>
                                                </x-slot:trigger>
                                            </x-modal-confirmation>
                                        @else
                                            <x-modal-confirmation title="Turn on automatic security updates?"
                                                warningMessage="Updates run without you. Your resources can stop for a short time during an update. You can turn this off at any time."
                                                submitAction="toggleAutomaticSecurityUpdates"
                                                temporaryDisableTwoStepConfirmation :confirmWithText="false"
                                                :confirmWithPassword="false" :actions="[
                                                    'Every day at the time in Maintenance window (time zone of the server), the server installs security updates without asking.',
                                                    'If Docker comes from the operating system packages (for example docker.io or moby-engine), a security update of Docker restarts it, and all containers on this server stop and start again. Docker from the Docker repository (docker-ce, as Coolify installs it) has no security updates there and is not updated.',
                                                    'Services that use an updated library (for example OpenSSL or glibc) can restart.',
                                                    'A new kernel is installed, but the server is not rebooted. Coolify then shows Reboot required.',
                                                ]"
                                                step2ButtonText="Turn on">
                                                <x-slot:trigger>
                                                    <button type="button" isHighlighted
                                                        class="h-6 shrink-0 rounded-md border px-2 text-[12px] font-medium">
                                                        Turn on
                                                    </button>
                                                </x-slot:trigger>
                                            </x-modal-confirmation>
                                        @endif
                                    </div>
                                @endcan
                            </div>
                        </div>
                        <x-forms.input canGate="update" :canResource="$server" id="autoSecurityUpdatesTime"
                                label="Maintenance window" placeholder="03:00"
                                helper="Daily start time (24-hour HH:MM) in the time zone of the server. Updates that need a reboot are installed, but the server is not rebooted." />
                        @endif
                    </div>

                    <x-callout type="info" title="Supported package managers">
                        Automated package discovery currently supports apk, apt, dnf, pacman, and zypper. Automatic
                        security updates are available for apt and dnf (Debian, Ubuntu, and RHEL family)@if ($packageManager && ! $autoUpdatesSupported); this server uses {{ $packageManager }}@endif.
                        Status notifications can be managed from
                        <a class="font-medium underline" href="{{ route('notifications.email') }}"
                            {{ wireNavigate() }}>notification settings</a>.
                    </x-callout>
                </div>
            </x-application.settings-section>
        </form>

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
            <x-application.settings-section id="server-package-updates-section" title="Package updates"
                helper="Available operating system updates for this server.">
                <x-callout type="danger" title="Could not check for updates">
                    {{ $error }}
                </x-callout>
            </x-application.settings-section>
        @elseif ($checkedAt && $totalUpdates === 0)
            <x-application.settings-section id="server-package-updates-section" title="Package updates"
                helper="Available operating system updates for this server.">
                <x-slot:badges>
                    <x-status-badge status="Up to date" type="success" />
                </x-slot:badges>
                <x-empty size="sm" title="Server is up to date"
                    description="No package updates are currently available." icon-name="check-circle" />
            </x-application.settings-section>
        @elseif ($totalUpdates > 0)
            <x-application.settings-section id="server-package-updates-section" title="Package updates"
                helper="{{ $totalUpdates }} {{ Str::plural('update', $totalUpdates) }} available{{ $securityUpdates !== null ? ', ' . $securityUpdates . ' of them security ' . Str::plural('update', $securityUpdates) : '' }}."
                flush>
                <x-slot:badges>
                    <x-status-badge :status="$totalUpdates . ' ' . Str::plural('update', $totalUpdates)" :type="$securityUpdates > 0 ? 'error' : 'warning'" />
                </x-slot:badges>
                <x-slot:actions>
                    <div class="w-56">
                        <x-table.search placeholder="Search packages" loading-target="search" maxlength="100"
                            wire:model.live.debounce.300ms="search" />
                    </div>
                    @can('update', $server)
                        @if ($selectedPackages !== [])
                            @php
                                $selectedRisky = collect($updates)->whereIn('package', $selectedPackages)->filter(fn ($update) => filled(data_get($update, 'risk')))->count();
                            @endphp
                            <x-forms.button type="button" wire:click="$set('selectedPackages', [])">Clear selection</x-forms.button>
                            {{-- The dialog has wire:ignore; a new key renders it again when the selection changes. --}}
                            <div wire:key="update-selected-{{ md5(implode(',', $selectedPackages)) }}">
                            <x-modal-confirmation title="Update selected packages?"
                                buttonTitle="Update {{ count($selectedPackages) }} selected" isHighlightedButton
                                submitAction="updateSelectedPackages" :actions="[
                                    'Coolify updates ' . implode(', ', $selectedPackages) . '.',
                                    $selectedRisky > 0
                                        ? $selectedRisky . ' of them can restart Docker, the running containers, or core system services, or need a reboot.'
                                        : 'Some services on the server can restart.',
                                ]"
                                warningMessage="Coolify cannot roll back a package update. Check the update logs when it is done."
                                :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Update" />
                            </div>
                        @else
                        <x-modal-confirmation title="Confirm package update?" buttonTitle="Update all packages"
                            isHighlightedButton submitAction="updateAllPackages" :actions="[
                                'All packages will be updated to their latest available versions.',
                                $riskyUpdates > 0
                                    ? $riskyUpdates . ' ' . Str::plural('update', $riskyUpdates) . ' can restart Docker, the running containers, or core system services, or need a reboot.'
                                    : 'Some services on the server can restart.',
                            ]"
                            confirmationText="Update All Packages"
                            confirmationLabel="Confirm by entering the text below"
                            shortConfirmationLabel="Confirmation" :confirmWithPassword="false"
                            step2ButtonText="Update All Packages" />
                        @endif
                    @endcan
                </x-slot:actions>

                @if ($fullUpgradeOnly)
                    <div class="px-4 pt-4">
                        <x-callout type="info" title="Full system upgrade only">
                            Arch Linux does not support partial upgrades, so packages cannot be updated one by one.
                        </x-callout>
                    </div>
                @endif

                @if ($shownUpdates->isEmpty())
                    <div class="p-6">
                        <x-empty size="sm" title="No matching updates"
                            description="Try another package name, or clear the search." icon-name="search" />
                    </div>
                @else
                <div class="data-table">
                    @php
                        $canSelect = ! $fullUpgradeOnly && auth()->user()?->can('update', $server);
                    @endphp
                    <div class="data-table-header package-updates-table-grid">
                        <span class="flex items-center">
                            @if ($canSelect)
                                <input type="checkbox" wire:click="toggleAllPackages" @checked($allMatchingSelected)
                                    aria-label="Select all packages" wire:loading.attr="disabled"
                                    class="size-4 cursor-pointer rounded-sm border-neutral-300 text-accent focus:ring-accent dark:border-white/20 dark:bg-transparent" />
                            @endif
                        </span>
                        <span>Package</span>
                        <span>New version</span>
                    </div>
                    @foreach ($shownUpdates as $update)
                        <div wire:key="package-update-{{ data_get($update, 'package') }}-{{ data_get($update, 'architecture') }}"
                            class="data-table-row package-updates-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]">
                            <div class="flex items-center">
                                @if ($canSelect)
                                    <input type="checkbox" wire:model.live="selectedPackages" value="{{ data_get($update, 'package') }}"
                                        id="select-package-{{ data_get($update, 'package') }}"
                                        aria-label="Select {{ data_get($update, 'package') }}"
                                        class="size-4 cursor-pointer rounded-sm border-neutral-300 text-accent focus:ring-accent dark:border-white/20 dark:bg-transparent" />
                                @endif
                            </div>
                            <label for="select-package-{{ data_get($update, 'package') }}" class="flex min-w-0 cursor-pointer items-center gap-2">
                                @if (filled(data_get($update, 'risk')))
                                    <span class="shrink-0" data-tooltip="{{ \App\Actions\Server\CheckUpdates::RISK_LABELS[data_get($update, 'risk')] ?? '' }}">
                                        <x-reicon name="alert-triangle" class="size-4 text-red-500" />
                                        <span class="sr-only">{{ \App\Actions\Server\CheckUpdates::RISK_LABELS[data_get($update, 'risk')] ?? '' }}</span>
                                    </span>
                                @endif
                                <span class="min-w-0 truncate font-mono text-[12px] text-neutral-950 dark:text-fg">
                                    {{ data_get($update, 'package') }}
                                </span>
                                @if (data_get($update, 'security'))
                                    <x-status-badge status="Security" type="error" />
                                @endif
                            </label>
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
                        </div>
                    @endforeach
                    <x-table-pagination :from="$shownUpdates->firstItem() ?? 0" :to="$shownUpdates->lastItem() ?? 0"
                        :total="$shownUpdates->total()" :current-page="$shownUpdates->currentPage()"
                        :last-page="$shownUpdates->lastPage()" wire-target="previousPage,nextPage,perPage"
                        previous-action="previousPage" next-action="nextPage">
                        <x-slot:pageSize>
                            <x-page-size-select model="perPage" livewire
                                storage-key="coolify.page-size.server-package-updates" />
                        </x-slot:pageSize>
                    </x-table-pagination>
                </div>
                @endif
            </x-application.settings-section>
        @endif

        @if (! $checking && ! $error && count($heldUpdates) > 0)
            <x-application.settings-section id="server-held-updates-section" title="Held back updates"
                helper="The package manager does not install these updates. Phased rollout: Ubuntu releases the update to a part of all servers at a time, and this server gets it in a later phase. Held: a package hold (apt-mark hold) or a version pin (/etc/apk/world) on the server blocks the update."
                flush>
                <x-slot:badges>
                    <x-status-badge :status="count($heldUpdates) . ' held'" type="warning" />
                </x-slot:badges>
                <div class="data-table">
                    <div class="data-table-header held-updates-table-grid">
                        <span>Package</span>
                        <span>New version</span>
                        <span class="text-right">Reason</span>
                    </div>
                    @foreach ($heldUpdates as $update)
                        <div wire:key="held-update-{{ data_get($update, 'package') }}-{{ data_get($update, 'architecture') }}"
                            class="data-table-row held-updates-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]">
                            <span class="min-w-0 truncate font-mono text-[12px] text-neutral-950 dark:text-fg">
                                {{ data_get($update, 'package') }}
                            </span>
                            <div class="min-w-0">
                                <p class="truncate font-mono text-[12px] text-neutral-700 dark:text-fg-dim">
                                    {{ data_get($update, 'new_version') }}
                                </p>
                                <p class="mt-0.5 truncate text-[10px] text-neutral-500 dark:text-fg-faint">
                                    Current: {{ data_get($update, 'current_version') }}
                                </p>
                            </div>
                            <div class="flex justify-end">
                                <x-status-badge :status="\App\Actions\Server\CheckUpdates::HELD_REASON_LABELS[data_get($update, 'held_reason')] ?? 'Held'" type="warning" />
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-application.settings-section>
        @endif

        @endif
    </div>
    </div>
</div>
