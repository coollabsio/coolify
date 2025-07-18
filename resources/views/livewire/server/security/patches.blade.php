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

    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }" class="flex flex-col h-full gap-8 sm:flex-row" x-init="$wire.checkForUpdates()">
        <x-server.sidebar-security :server="$server" :parameters="$parameters" />
        <form wire:submit='submit' class="w-full">
            <div>
                <div class="flex items-center gap-2 flex-row">
                    <h2>Server Patching</h2>
                    <span class="text-xs text-neutral-500">(experimental)</span>
                    <x-helper
                        helper="Only available for apt, dnf and zypper package managers atm, more coming
            soon.<br/>Status notifications sent every week.<br/>You can disable notifications in the <a class='dark:text-white underline' href='{{ route('notifications.email') }}'>notification settings</a>." />
                    <x-forms.button type="button" wire:click="$dispatch('checkForUpdatesDispatch')">
                        Check Now</x-forms.button>
                </div>
                <div>Update your servers semi-automatically.</div>
                <div>
                    <div class="flex flex-col gap-6 pt-4">
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
                                            <x-modal-confirmation title="Confirm package update?"
                                                buttonTitle="Update All
                                            Packages"
                                                isHighlightedButton submitAction="updateAllPackages" dispatchAction
                                                :actions="[
                                                    'All packages will be updated to the latest version.',
                                                    'This action could restart your currently running containers if docker will be updated.',
                                                ]" confirmationText="Update All Packages"
                                                confirmationLabel="Please confirm the execution of the actions by entering the name below"
                                                shortConfirmationLabel="Name" :confirmWithPassword=false
                                                step2ButtonText="Update All
                                            Packages" />
                                        </div>
                                        <table>
                                            <thead>
                                                <tr>
                                                    <th>Package</th>
                                                    @if ($packageManager !== 'dnf')
                                                        <th>Current Version</th>
                                                    @endif
                                                    <th>New Version</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($updates as $update)
                                                    <tr>
                                                        <td class="inline-flex gap-2 justify-center items-center">
                                                            @if (data_get_str($update, 'package')->contains('docker') || data_get_str($update, 'package')->contains('kernel'))
                                                                <x-helper :helper="'This package will restart your currently running containers'">
                                                                    <x-slot:icon>
                                                                        <svg class="w-4 h-4 text-red-500 block"
                                                                            viewBox="0 0 256 256"
                                                                            xmlns="http://www.w3.org/2000/svg">
                                                                            <path fill="currentColor"
                                                                                d="M240.26 186.1L152.81 34.23a28.74 28.74 0 0 0-49.62 0L15.74 186.1a27.45 27.45 0 0 0 0 27.71A28.31 28.31 0 0 0 40.55 228h174.9a28.31 28.31 0 0 0 24.79-14.19a27.45 27.45 0 0 0 .02-27.71m-20.8 15.7a4.46 4.46 0 0 1-4 2.2H40.55a4.46 4.46 0 0 1-4-2.2a3.56 3.56 0 0 1 0-3.73L124 46.2a4.77 4.77 0 0 1 8 0l87.44 151.87a3.56 3.56 0 0 1 .02 3.73M116 136v-32a12 12 0 0 1 24 0v32a12 12 0 0 1-24 0m28 40a16 16 0 1 1-16-16a16 16 0 0 1 16 16">
                                                                            </path>
                                                                        </svg>
                                                                    </x-slot:icon>
                                                                </x-helper>
                                                            @endif
                                                            {{ data_get($update, 'package') }}
                                                        </td>
                                                        @if ($packageManager !== 'dnf')
                                                            <td>{{ data_get($update, 'current_version') }}</td>
                                                        @endif
                                                        <td>{{ data_get($update, 'new_version') }}</td>
                                                        <td>
                                                            <x-forms.button type="button"
                                                                wire:click="$dispatch('updatePackage', { package: '{{ data_get($update, 'package') }}' })">Update</x-forms.button>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
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
