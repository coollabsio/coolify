<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Security | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <x-slide-over closeWithX fullScreen @startupdate.window="slideOverOpen = true">
        <x-slot:title>Updating Packages</x-slot:title>
        <x-slot:content>
            <livewire:activity-monitor header="Logs" />
        </x-slot:content>
    </x-slide-over>

    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }" class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar-security :server="$server" :parameters="$parameters" />
        <form wire:submit='submit' class="w-full">
            <div>
                <div class="flex items-center gap-2 flex-row">
                    <h2>Server Patching</h2>
                    <span class="text-xs text-neutral-500">(experimental)</span>
                    <x-helper
                        helper="Only available for apt, dnf, zypper, and nixos package managers atm, more coming
            soon.<br/>Status notifications sent every week.<br/>You can disable notifications in the <a class='dark:text-white underline' href='{{ route('notifications.email') }}'>notification settings</a>." />
                    @if (isDev())
                        <x-forms.button type="button" wire:click="sendTestEmail">
                            Send Test Email (dev only)</x-forms.button>
                    @endif
                </div>
                <div>Update your servers semi-automatically.</div>
                <div>
                    <div class="flex flex-col gap-6 pt-4">
                        <x-forms.button type="button" wire:click="$dispatch('checkForUpdates')">
                            Check for Updates</x-forms.button>
                        <div class="flex flex-col">
                            <div>
                                <div class="pb-2" wire:target="checkForUpdates" wire:loading>
                                    Checking for updates. It may take a few minutes. <x-loading />
                                </div>
                                @if ($error)
                                    <div class="text-red-500">{{ $error }}</div>
                                @else
                                    @if ($totalUpdates === 0)
                                        <div class="text-green-500">Your server is up to date.</div>
                                    @endif
                                    @if (isset($updates) && count($updates) > 0)
                                        <div class="pb-2">
                                            @if ($packageManager === 'nixos')
                                                <div class="mb-4 p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg">
                                                    <div class="flex items-center gap-2 mb-2">
                                                        <svg class="w-5 h-5 text-yellow-600 dark:text-yellow-400" viewBox="0 0 256 256" xmlns="http://www.w3.org/2000/svg">
                                                            <path fill="currentColor" d="M240.26 186.1L152.81 34.23a28.74 28.74 0 0 0-49.62 0L15.74 186.1a27.45 27.45 0 0 0 0 27.71A28.31 28.31 0 0 0 40.55 228h174.9a28.31 28.31 0 0 0 24.79-14.19a27.45 27.45 0 0 0 .02-27.71m-20.8 15.7a4.46 4.46 0 0 1-4 2.2H40.55a4.46 4.46 0 0 1-4-2.2a3.56 3.56 0 0 1 0-3.73L124 46.2a4.77 4.77 0 0 1 8 0l87.44 151.87a3.56 3.56 0 0 1 .02 3.73M116 136v-32a12 12 0 0 1 24 0v32a12 12 0 0 1-24 0m28 40a16 16 0 1 1-16-16a16 16 0 0 1 16 16"/>
                                                        </svg>
                                                        <h3 class="font-medium text-yellow-800 dark:text-yellow-200">NixOS System Update Notice</h3>
                                                    </div>
                                                    <div class="text-sm text-yellow-700 dark:text-yellow-300">
                                                        <p class="mb-2">NixOS uses atomic system-wide updates. This will:</p>
                                                        <ul class="list-disc list-inside space-y-1 ml-2">
                                                            <li>Update the entire system configuration atomically</li>
                                                            <li>Rebuild the system with latest channel packages</li>
                                                            <li>May require system reboot</li>
                                                            <li>Could temporarily interrupt running services</li>
                                                        </ul>
                                                        <p class="mt-2 font-medium">Ensure you have backups and maintenance window available.</p>
                                                    </div>
                                                </div>
                                            @endif
                                            <x-modal-confirmation title="Confirm package update?"
                                                buttonTitle="Update All
                                            Packages"
                                                isHighlightedButton submitAction="updateAllPackages" dispatchAction
                                                :actions="[
                                                    'All packages will be updated to the latest version.',
                                                    $packageManager === 'nixos' ? 'NixOS will perform atomic system rebuild - services may be interrupted.' : 'This action could restart your currently running containers if docker will be updated.',
                                                ]" confirmationText="Update All Packages"
                                                confirmationLabel="Please confirm the execution of the actions by entering the name below"
                                                shortConfirmationLabel="Name" :confirmWithPassword=false
                                                step2ButtonText="Update All
                                            Packages" />
                                        </div>
                                        <div class="overflow-x-auto">
                                            <table class="min-w-full">
                                                <thead>
                                                    <tr>
                                                        <th>Package</th>
                                                        <th>Version</th>
                                                        <th>Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($updates as $update)
                                                        <tr>
                                                            <td>
                                                                <div class="flex gap-2 items-center">
                                                                    @if (data_get_str($update, 'package')->contains('docker') || data_get_str($update, 'package')->contains('kernel') || data_get($update, 'is_system_update'))
                                                                        <x-helper :helper="data_get($update, 'is_system_update') ? 'This is a system-wide update that may interrupt services' : 'This package will restart your currently running containers'">
                                                                            <x-slot:icon>
                                                                                <svg class="w-4 h-4 text-red-500 block flex-shrink-0"
                                                                                    viewBox="0 0 256 256"
                                                                                    xmlns="http://www.w3.org/2000/svg">
                                                                                    <path fill="currentColor"
                                                                                        d="M240.26 186.1L152.81 34.23a28.74 28.74 0 0 0-49.62 0L15.74 186.1a27.45 27.45 0 0 0 0 27.71A28.31 28.31 0 0 0 40.55 228h174.9a28.31 28.31 0 0 0 24.79-14.19a27.45 27.45 0 0 0 .02-27.71m-20.8 15.7a4.46 4.46 0 0 1-4 2.2H40.55a4.46 4.46 0 0 1-4-2.2a3.56 3.56 0 0 1 0-3.73L124 46.2a4.77 4.77 0 0 1 8 0l87.44 151.87a3.56 3.56 0 0 1 .02 3.73M116 136v-32a12 12 0 0 1 24 0v32a12 12 0 0 1-24 0m28 40a16 16 0 1 1-16-16a16 16 0 0 1 16 16">
                                                                                    </path>
                                                                                </svg>
                                                                            </x-slot:icon>
                                                                        </x-helper>
                                                                    @endif
                                                                    <div>
                                                                        <span class="break-all">{{ data_get($update, 'package') }}</span>
                                                                        @if (data_get($update, 'description'))
                                                                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ data_get($update, 'description') }}</div>
                                                                        @endif
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td class="whitespace-nowrap">
                                                                <div class="flex gap-1 items-center">
                                                                    <span>{{ data_get($update, 'new_version') }}</span>
                                                                    @if ($packageManager !== 'dnf' && data_get($update, 'current_version'))
                                                                        <x-helper helper="Current: {{ data_get($update, 'current_version') }}" />
                                                                    @endif
                                                                    @if (data_get($update, 'package_count') && data_get($update, 'package_count') !== 'unknown')
                                                                        <span class="text-xs text-gray-500">({{ data_get($update, 'package_count') }} packages)</span>
                                                                    @endif
                                                                </div>
                                                            </td>
                                                            <td class="whitespace-nowrap">
                                                                @if ($packageManager === 'nixos')
                                                                    <x-forms.button type="button"
                                                                        wire:click="$dispatch('updateAllPackages')">Update System</x-forms.button>
                                                                @else
                                                                    <x-forms.button type="button"
                                                                        wire:click="$dispatch('updatePackage', { package: '{{ data_get($update, 'package') }}' })">Update</x-forms.button>
                                                                @endif
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    @script
        <script>
            $wire.on('checkForUpdates', () => {
                $wire.$call('checkForUpdatesDispatch');
            });
            $wire.on('updateAllPackages', () => {
                window.dispatchEvent(new CustomEvent('startupdate'));
                $wire.$call('updateAllPackages');
            });
            $wire.on('updatePackage', (data) => {
                window.dispatchEvent(new CustomEvent('startupdate'));
                $wire.$call('updatePackage', data.package);
            });
            $wire.on('checkForUpdatesDispatch', () => {
                $wire.$call('checkForUpdates');
            });
        </script>
    @endscript
</div>
