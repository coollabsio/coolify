<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Security | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <x-slide-over closeWithX fullScreen @startupdate.window="slideOverOpen = true">
        <x-slot:title>Updating packages</x-slot:title>
        <x-slot:content>
            <livewire:activity-monitor header="Logs" />
        </x-slot:content>
    </x-slide-over>

    <div
        class="server-settings-workspace application-settings-workspace mt-8 grid w-full max-w-[1180px] min-w-0 gap-8 xl:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
        <x-server.sidebar-security :server="$server" :parameters="$parameters" />

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
                    <x-forms.button type="button" wire:click="$dispatch('checkForUpdates')">
                        <x-reicon name="refresh" class="size-3.5" />
                        Check for updates
                    </x-forms.button>
                </x-slot:actions>

                <x-callout type="info" title="Supported package managers">
                    Automated package discovery currently supports apt, dnf, and zypper. Weekly status notifications
                    can be managed from
                    <a class="font-medium underline" href="{{ route('notifications.email') }}"
                        {{ wireNavigate() }}>notification settings</a>.
                </x-callout>
            </x-application.settings-section>

            <div wire:loading wire:target="checkForUpdates">
                <x-application.settings-section title="Checking for updates"
                    helper="Package discovery can take several minutes.">
                    <div class="flex items-center gap-3 py-4 text-sm text-neutral-600 dark:text-fg-dim">
                        <x-loading />
                        Inspecting installed packages…
                    </div>
                </x-application.settings-section>
            </div>

            <div wire:loading.remove wire:target="checkForUpdates">
                @if ($error)
                    <x-application.settings-section title="Package updates"
                        helper="Available operating system updates for this server.">
                        <x-callout type="danger" title="Could not check for updates">
                            {{ $error }}
                        </x-callout>
                    </x-application.settings-section>
                @elseif ($totalUpdates === 0)
                    <x-application.settings-section title="Package updates"
                        helper="Available operating system updates for this server.">
                        <x-empty size="sm" title="Server is up to date"
                            description="No package updates are currently available.">
                            <x-slot:icon>
                                <x-reicon name="check-circle" class="size-8" />
                            </x-slot:icon>
                        </x-empty>
                    </x-application.settings-section>
                @elseif (isset($updates) && count($updates) > 0)
                    <x-application.settings-section id="server-package-updates-section" title="Package updates"
                        helper="{{ $totalUpdates }} update{{ $totalUpdates === 1 ? '' : 's' }} available."
                        flush>
                        <x-slot:actions>
                            <x-modal-confirmation title="Confirm package update?"
                                buttonTitle="Update all packages" isHighlightedButton
                                submitAction="updateAllPackages" dispatchAction :actions="[
                                    'All packages will be updated to their latest available versions.',
                                    'Docker or kernel updates may restart running containers.',
                                ]" confirmationText="Update All Packages"
                                confirmationLabel="Confirm by entering the text below"
                                shortConfirmationLabel="Confirmation" :confirmWithPassword="false"
                                step2ButtonText="Update All Packages" />
                        </x-slot:actions>

                        <div class="data-table">
                            <div class="data-table-header package-updates-table-grid">
                                <span>Package</span>
                                <span>New version</span>
                                <span class="text-right">Action</span>
                            </div>
                            @foreach ($updates as $update)
                                <div
                                    class="data-table-row package-updates-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]">
                                    <div class="flex min-w-0 items-center gap-2">
                                        @if (data_get_str($update, 'package')->contains('docker') || data_get_str($update, 'package')->contains('kernel'))
                                            <x-reicon name="alert-triangle"
                                                class="size-4 shrink-0 text-red-500" />
                                        @endif
                                        <span class="min-w-0 truncate font-mono text-[12px] text-neutral-950 dark:text-fg">
                                            {{ data_get($update, 'package') }}
                                        </span>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="truncate font-mono text-[12px] text-neutral-700 dark:text-fg-dim">
                                            {{ data_get($update, 'new_version') }}
                                        </p>
                                        @if ($packageManager !== 'dnf' && data_get($update, 'current_version'))
                                            <p class="mt-0.5 truncate text-[10px] text-neutral-500 dark:text-fg-faint">
                                                Current: {{ data_get($update, 'current_version') }}
                                            </p>
                                        @endif
                                    </div>
                                    <div class="flex justify-end">
                                        <x-forms.button type="button"
                                            wire:click="$dispatch('updatePackage', { package: '{{ data_get($update, 'package') }}' })">
                                            Update
                                        </x-forms.button>
                                    </div>
                                </div>
                            @endforeach
                            <div
                                class="flex min-h-11 items-center border-t border-neutral-200 px-4 text-[11px] text-neutral-500 dark:border-white/[0.08] dark:text-fg-faint">
                                {{ count($updates) }} {{ Str::plural('package update', count($updates)) }}
                            </div>
                        </div>
                    </x-application.settings-section>
                @endif
            </div>
        </div>
    </div>

    @script
        <script>
            $wire.on('checkForUpdates', () => $wire.$call('checkForUpdatesDispatch'));
            $wire.on('updateAllPackages', () => {
                window.dispatchEvent(new CustomEvent('startupdate'));
                $wire.$call('updateAllPackages');
            });
            $wire.on('updatePackage', data => {
                window.dispatchEvent(new CustomEvent('startupdate'));
                $wire.$call('updatePackage', data.package);
            });
            $wire.on('checkForUpdatesDispatch', () => $wire.$call('checkForUpdates'));
        </script>
    @endscript
</div>
