<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Advanced | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }" class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="advanced" />
        <form wire:submit='submit' class="w-full">
            <div>
                <div class="flex items-center gap-2">
                    <h2>Advanced</h2>
                    <x-forms.button type="submit">Save</x-forms.button>
                </div>
                <div class="mb-4">Advanced configuration for your server.</div>
            </div>

            <div class="flex items-center gap-2">
                <h3>Terminal Access</h3>
                <x-helper
                    helper="Control whether terminal access is available for this server and its containers.<br/>Only team
                    administrators and owners can modify this setting." />
                @if ($isTerminalEnabled)
                    <span
                        class="px-2 py-1 text-xs font-semibold text-green-800 bg-green-100 rounded dark:text-green-100 dark:bg-green-800">
                        Enabled
                    </span>
                @else
                    <span
                        class="px-2 py-1 text-xs font-semibold text-red-800 bg-red-100 rounded dark:text-red-100 dark:bg-red-800">
                        Disabled
                    </span>
                @endif
            </div>
            <div class="flex flex-col gap-4">
                <div class="flex items-center gap-4 pt-4">
                    @if (auth()->user()->isAdmin())
                        <div wire:key="terminal-access-change-{{ $isTerminalEnabled }}" class="pb-4">
                            <x-modal-confirmation title="Confirm Terminal Access Change?"
                                temporaryDisableTwoStepConfirmation
                                buttonTitle="{{ $isTerminalEnabled ? 'Disable Terminal' : 'Enable Terminal' }}"
                                submitAction="toggleTerminal" :actions="[
                                    $isTerminalEnabled
                                        ? 'This will disable terminal access for this server and all its containers.'
                                        : 'This will enable terminal access for this server and all its containers.',
                                    $isTerminalEnabled
                                        ? 'Users will no longer be able to access terminal views from the UI.'
                                        : 'Users will be able to access terminal views from the UI.',
                                    'This change will take effect immediately.',
                                ]" confirmationText="{{ $server->name }}"
                                shortConfirmationLabel="Server Name"
                                step3ButtonText="{{ $isTerminalEnabled ? 'Disable Terminal' : 'Enable Terminal' }}">
                            </x-modal-confirmation>
                        </div>
                    @endif
                </div>
            </div>

            <h3>Disk Usage</h3>
            <div class="flex flex-col gap-6">
                <div class="flex flex-col">
                    <div class="flex flex-wrap gap-2 sm:flex-nowrap pt-4">
                        <x-forms.input placeholder="0 23 * * *" id="serverDiskUsageCheckFrequency"
                            label="Disk usage check frequency" required
                            helper="Cron expression for disk usage check frequency.<br>You can use every_minute, hourly, daily, weekly, monthly, yearly.<br><br>Default is every night at 11:00 PM." />
                        <x-forms.input id="serverDiskUsageNotificationThreshold"
                            label="Server disk usage notification threshold (%)" required
                            helper="If the server disk usage exceeds this threshold, Coolify will send a notification to the team members." />
                    </div>
                </div>

                <div class="flex flex-col">
                    <h3>Builds</h3>
                    <div class="flex flex-wrap gap-2 sm:flex-nowrap pt-4">
                        <x-forms.input id="concurrentBuilds" label="Number of concurrent builds" required
                            helper="You can specify the number of simultaneous build processes/deployments that should run concurrently." />
                        <x-forms.input id="dynamicTimeout" label="Deployment timeout (seconds)" required
                            helper="You can define the maximum duration for a deployment to run before timing it out." />
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
